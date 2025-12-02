const express = require('express');
const fs = require('fs');
const csv = require('csv-parser');
const app = express();
const PORT = 3000;

// --- CONFIGURATION ---
const BOOK_CSV = 'Mevs_English_Library 2025 - Sheet1.csv';
const TRANS_CSV = 'transactions.csv';
const ADMIN_PASSWORD = "Betzalel Tusk"; // <--- CHANGE THIS PASSWORD IF YOU WANT

app.use(express.json());
app.use(express.static('public'));

// --- MEMORY STORE ---
let books = [];

// Load books function
function loadBooks() {
    const tempBooks = [];
    if (fs.existsSync(BOOK_CSV)) {
        fs.createReadStream(BOOK_CSV)
            .pipe(csv())
            .on('data', (row) => {
                if (row.Name) {
                    tempBooks.push({
                        name: row.Name,
                        category: row.Category || 'Uncategorized',
                        shelf: row.Shelf || 'Unknown',
                        copies: parseInt(row.Copies) || 1
                    });
                }
            })
            .on('end', () => {
                books = tempBooks;
                console.log(`📚 Database reloaded: ${books.length} books found.`);
            });
    } else {
        console.error("❌ CSV File not found!");
    }
}

// Ensure transaction file exists
if (!fs.existsSync(TRANS_CSV)) {
    fs.writeFileSync(TRANS_CSV, 'Student,Book,Action,Date\n');
}

// --- API ENDPOINTS ---

// 1. Get Catalog
app.get('/api/books', (req, res) => {
    const transactions = [];
    if (fs.existsSync(TRANS_CSV)) {
        fs.createReadStream(TRANS_CSV)
            .pipe(csv())
            .on('data', (row) => transactions.push(row))
            .on('end', () => {
                const loans = {};
                transactions.forEach(t => {
                    if (!loans[t.Book]) loans[t.Book] = 0;
                    if (t.Action === 'Borrow') loans[t.Book]++;
                    if (t.Action === 'Return') loans[t.Book]--;
                });

                const catalog = books.map(b => {
                    const activeLoans = loans[b.name] || 0;
                    return {
                        ...b,
                        available: Math.max(0, b.copies - activeLoans),
                        total: b.copies
                    };
                });
                res.json(catalog);
            });
    } else {
        res.json(books);
    }
});

// 2. Get Students (For Return dropdown)
app.get('/api/students', (req, res) => {
    const transactions = [];
    if (fs.existsSync(TRANS_CSV)) {
        fs.createReadStream(TRANS_CSV)
            .pipe(csv())
            .on('data', (data) => transactions.push(data))
            .on('end', () => {
                const studentLoans = {};
                transactions.forEach(t => {
                    if (!studentLoans[t.Student]) studentLoans[t.Student] = {};
                    if (!studentLoans[t.Student][t.Book]) studentLoans[t.Student][t.Book] = 0;

                    if (t.Action === 'Borrow') studentLoans[t.Student][t.Book]++;
                    if (t.Action === 'Return') studentLoans[t.Student][t.Book]--;
                });

                const activeStudents = [];
                for (const [student, books] of Object.entries(studentLoans)) {
                    const borrowedBooks = [];
                    for (const [book, count] of Object.entries(books)) {
                        if (count > 0) borrowedBooks.push(book);
                    }
                    if (borrowedBooks.length > 0) {
                        activeStudents.push({ name: student, books: borrowedBooks });
                    }
                }
                res.json(activeStudents);
            });
    } else {
        res.json([]);
    }
});

// 3. ADMIN: Get Active Loans + Overdue Check
app.post('/api/admin/active', (req, res) => {
    const { password } = req.body;
    if (password !== ADMIN_PASSWORD) return res.status(403).json({ error: "Wrong Password" });

    const transactions = [];
    if (fs.existsSync(TRANS_CSV)) {
        fs.createReadStream(TRANS_CSV)
            .pipe(csv())
            .on('data', (row) => transactions.push(row))
            .on('end', () => {
                const activeMap = {};

                transactions.forEach(t => {
                    const key = `${t.Student}|${t.Book}`;
                    if (t.Action === 'Borrow') {
                        activeMap[key] = t.Date;
                    } else if (t.Action === 'Return') {
                        delete activeMap[key];
                    }
                });

                const now = new Date();
                const result = Object.keys(activeMap).map(key => {
                    const [student, book] = key.split('|');
                    const borrowDate = new Date(activeMap[key]);

                    // Calculate days overdue
                    const diffTime = Math.abs(now - borrowDate);
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                    const isOverdue = diffDays > 14;

                    return {
                        student,
                        book,
                        date: activeMap[key],
                        daysHeld: diffDays,
                        isOverdue
                    };
                });

                res.json(result);
            });
    } else {
        res.json([]);
    }
});

// 4. ADMIN: Get Full History
app.post('/api/admin/history', (req, res) => {
    const { password } = req.body;
    if (password !== ADMIN_PASSWORD) return res.status(403).json({ error: "Wrong Password" });

    const transactions = [];
    if (fs.existsSync(TRANS_CSV)) {
        fs.createReadStream(TRANS_CSV)
            .pipe(csv())
            .on('data', (row) => transactions.push(row))
            .on('end', () => {
                // Return reversed array (newest first)
                res.json(transactions.reverse());
            });
    } else {
        res.json([]);
    }
});

// 5. ADMIN: Reload Catalog
app.post('/api/admin/reload', (req, res) => {
    const { password } = req.body;
    if (password !== ADMIN_PASSWORD) return res.status(403).json({ error: "Wrong Password" });

    loadBooks();
    res.json({ success: true, message: "Catalog Reloaded" });
});

// 6. Save Transaction
app.post('/api/transaction', (req, res) => {
    const { student, book, action } = req.body;
    if (!student || !book) return res.status(400).json({ error: 'Missing fields' });

    const date = new Date().toLocaleString();
    const cleanStudent = student.replace(/"/g, '""');
    const cleanBook = book.replace(/"/g, '""');

    const line = `"${cleanStudent}","${cleanBook}","${action}","${date}"\n`;

    fs.appendFile(TRANS_CSV, line, (err) => {
        if (err) return res.status(500).json({ error: 'Failed to write' });
        res.json({ success: true });
    });
});

// --- FRONTEND ---
app.get('/', (req, res) => {
    res.send(`
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Library System</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background-color: #f8f9fa; padding: 20px; }
            .card { margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
            .nav-pills .nav-link { cursor: pointer; }
            .nav-pills .nav-link.active { background-color: #0d6efd; color: white !important; }
            .table-responsive { max-height: 50vh; overflow-y: auto; }
            .overdue { background-color: #ffe6e6 !important; color: #cc0000; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1 class="mb-4 text-center">📚 Library Manager</h1>
            
            <ul class="nav nav-pills mb-4 justify-content-center">
                <li class="nav-item"><a class="nav-link active" onclick="showPage('catalog', this)">📖 Catalogue</a></li>
                <li class="nav-item"><a class="nav-link" onclick="showPage('borrow', this)">📤 Sign Out</a></li>
                <li class="nav-item"><a class="nav-link" onclick="showPage('return', this)">📥 Sign In</a></li>
                <li class="nav-item"><a class="nav-link bg-dark text-white ms-3" onclick="checkAdmin()">🔐 Admin</a></li>
            </ul>

            <div id="catalog" class="page-section">
                <div class="card p-3">
                    <input type="text" id="searchBox" class="form-control mb-3" placeholder="🔍 Search titles..." onkeyup="filterCatalog()">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead class="sticky-top bg-white"><tr><th>Title</th><th>Category</th><th>Shelf</th><th>Status</th></tr></thead>
                            <tbody id="bookTable"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="borrow" class="page-section d-none">
                <div class="card p-4 mx-auto" style="max-width: 500px;">
                    <h3>Check Out Book</h3>
                    <div class="mb-3">
                        <label>Student Name</label>
                        <input type="text" id="borrowStudent" class="form-control" placeholder="Enter Name...">
                    </div>
                    <div class="mb-3">
                        <label>Select Book</label>
                        <select id="borrowBookSelect" class="form-control"></select>
                    </div>
                    <button onclick="submitTransaction('Borrow')" class="btn btn-primary w-100">Confirm Borrow</button>
                </div>
            </div>

            <div id="return" class="page-section d-none">
                <div class="card p-4 mx-auto" style="max-width: 500px;">
                    <h3>Return Book</h3>
                    <div class="mb-3">
                        <label>Select Student</label>
                        <select id="returnStudentSelect" class="form-control" onchange="updateReturnBooks()"></select>
                    </div>
                    <div class="mb-3">
                        <label>Select Book to Return</label>
                        <select id="returnBookSelect" class="form-control"></select>
                    </div>
                    <button onclick="submitTransaction('Return')" class="btn btn-success w-100">Confirm Return</button>
                </div>
            </div>

            <div id="admin" class="page-section d-none">
                <div class="card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3>🔐 Admin Dashboard</h3>
                        <button onclick="reloadCatalog()" class="btn btn-warning btn-sm">↻ Reload Catalog from CSV</button>
                    </div>

                    <h5 class="text-primary mt-4">⚠️ Current Active Loans</h5>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered">
                            <thead class="table-dark"><tr><th>Student</th><th>Book</th><th>Date Borrowed</th><th>Days Held</th></tr></thead>
                            <tbody id="adminActiveTable"></tbody>
                        </table>
                    </div>

                    <h5 class="text-secondary">📜 Transaction History (In & Out)</h5>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead><tr><th>Date</th><th>Action</th><th>Student</th><th>Book</th></tr></thead>
                            <tbody id="adminHistoryTable"></tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <script>
            let allBooks = [];
            let activeStudents = [];
            let adminPassword = "";

            function showPage(pageId, btn) {
                document.querySelectorAll('.page-section').forEach(el => el.classList.add('d-none'));
                document.querySelectorAll('.nav-link').forEach(el => el.classList.remove('active'));
                
                document.getElementById(pageId).classList.remove('d-none');
                if(btn) btn.classList.add('active');

                if(pageId === 'catalog') loadCatalog();
                if(pageId === 'borrow') loadBorrowList();
                if(pageId === 'return') loadReturnList();
            }

            // --- ADMIN SECURITY ---
            async function checkAdmin() {
                const input = prompt("Enter Admin Password:");
                if(!input) return;
                
                adminPassword = input;
                const res = await fetch('/api/admin/active', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ password: adminPassword })
                });

                if(res.status === 403) {
                    alert("Incorrect Password!");
                } else {
                    document.getElementById('admin').classList.remove('d-none');
                    document.querySelectorAll('.page-section').forEach(el => {
                        if(el.id !== 'admin') el.classList.add('d-none');
                    });
                    loadAdminData(await res.json());
                    loadHistory();
                }
            }

            // --- ADMIN DATA LOADING ---
            function loadAdminData(loans) {
                const tbody = document.getElementById('adminActiveTable');
                let overdueCount = 0;
                let overdueNames = [];

                if (loans.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center">No books checked out.</td></tr>';
                } else {
                    tbody.innerHTML = loans.map(l => {
                        if(l.isOverdue) {
                            overdueCount++;
                            overdueNames.push(l.student + " (" + l.book + ")");
                        }
                        const rowClass = l.isOverdue ? 'overdue' : '';
                        const dateText = new Date(l.date).toLocaleDateString() + ' ' + new Date(l.date).toLocaleTimeString();
                        return '<tr class="'+rowClass+'"><td>'+l.student+'</td><td>'+l.book+'</td><td>'+dateText+'</td><td>'+l.daysHeld+' days</td></tr>';
                    }).join('');
                }

                if(overdueCount > 0) {
                    alert("⚠️ WARNING: There are " + overdueCount + " overdue books!\\n\\n" + overdueNames.join("\\n"));
                }
            }

            async function loadHistory() {
                const res = await fetch('/api/admin/history', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ password: adminPassword })
                });
                const history = await res.json();
                const tbody = document.getElementById('adminHistoryTable');
                
                tbody.innerHTML = history.map(h => {
                    const color = h.Action === 'Borrow' ? 'text-danger' : 'text-success';
                    return '<tr><td>'+h.Date+'</td><td class="'+color+' fw-bold">'+h.Action+'</td><td>'+h.Student+'</td><td>'+h.Book+'</td></tr>';
                }).join('');
            }

            async function reloadCatalog() {
                const res = await fetch('/api/admin/reload', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ password: adminPassword })
                });
                if(res.ok) alert("✅ Catalog reloaded from CSV file!");
            }

            // --- STANDARD FEATURES ---
            async function loadCatalog() {
                const res = await fetch('/api/books');
                allBooks = await res.json();
                renderTable(allBooks);
            }

            function renderTable(books) {
                const tbody = document.getElementById('bookTable');
                tbody.innerHTML = books.map(b => {
                    const status = b.available > 0 
                        ? '<span class="badge bg-success">Available ('+b.available+')</span>' 
                        : '<span class="badge bg-danger">Out of Stock</span>';
                    return '<tr><td>'+b.name+'</td><td>'+b.category+'</td><td>'+b.shelf+'</td><td>'+status+'</td></tr>';
                }).join('');
            }

            function filterCatalog() {
                const term = document.getElementById('searchBox').value.toLowerCase();
                const filtered = allBooks.filter(b => b.name.toLowerCase().includes(term));
                renderTable(filtered);
            }

            async function loadBorrowList() {
                await loadCatalog(); 
                const select = document.getElementById('borrowBookSelect');
                const available = allBooks.filter(b => b.available > 0);
                select.innerHTML = available.map(b => '<option value="'+b.name+'">'+b.name+'</option>').join('');
            }

            async function loadReturnList() {
                const res = await fetch('/api/students');
                activeStudents = await res.json();
                const studentSelect = document.getElementById('returnStudentSelect');
                
                if(activeStudents.length === 0) {
                    studentSelect.innerHTML = '<option>No active loans</option>';
                    document.getElementById('returnBookSelect').innerHTML = '';
                    return;
                }
                studentSelect.innerHTML = '<option value="">Select Student...</option>' + 
                    activeStudents.map(s => '<option value="'+s.name+'">'+s.name+'</option>').join('');
            }

            function updateReturnBooks() {
                const studentName = document.getElementById('returnStudentSelect').value;
                const student = activeStudents.find(s => s.name === studentName);
                const bookSelect = document.getElementById('returnBookSelect');
                
                if (student) {
                    bookSelect.innerHTML = student.books.map(b => '<option value="'+b+'">'+b+'</option>').join('');
                } else {
                    bookSelect.innerHTML = '';
                }
            }

            async function submitTransaction(action) {
                let student, book;
                if (action === 'Borrow') {
                    student = document.getElementById('borrowStudent').value;
                    book = document.getElementById('borrowBookSelect').value;
                    if(!student || !book) return alert('Please fill all fields');
                } else {
                    student = document.getElementById('returnStudentSelect').value;
                    book = document.getElementById('returnBookSelect').value;
                    if(!student || !book) return alert('Please fill all fields');
                }

                const res = await fetch('/api/transaction', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ student, book, action })
                });

                if(res.ok) {
                    alert(action + ' Successful!');
                    if(action === 'Borrow') {
                        document.getElementById('borrowStudent').value = '';
                        loadBorrowList();
                    } else {
                        loadReturnList();
                    }
                } else {
                    alert('Error saving transaction');
                }
            }

            loadBooks();
        </script>
    </body>
    </html>
    `);
});

loadBooks();
app.listen(PORT, () => console.log('Library App running at http://localhost:' + PORT));