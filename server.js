const express = require('express');
const fs = require('fs');
const csv = require('csv-parser');
const app = express();
const PORT = 3000;

// --- CONFIGURATION ---
const BOOK_CSV = 'Mevs_English_Library 2025 - Sheet1.csv';
const TRANS_CSV = 'transactions.csv';
const ADMIN_PASSWORD = "1234"; // <--- CHANGE PASSWORD HERE

app.use(express.json());
// This line automatically serves index.html from the 'public' folder
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

loadBooks();
app.listen(PORT, () => console.log('Library App running at http://localhost:' + PORT));