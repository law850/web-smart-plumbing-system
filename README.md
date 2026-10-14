# 🔧 Smart Plumbing System

An AI-powered plumbing marketplace connecting customers with verified plumbers, with secure M-Pesa STK Push payments.

## ✨ Features

- 🤖 **AI-powered plumber matching** — classifies issues and scores plumbers by specialty, level, and rating
- 👥 **Three user roles** — Customer, Plumber, Admin
- 💳 **M-Pesa STK Push integration** — pay directly from your phone
- 📊 **Role-based dashboards** — tailored interfaces for each role
- ⭐ **Ratings & reviews** — customers rate plumbers after each job
- 💰 **Automated revenue split** — 35% plumber, 65% platform

## 🛠️ Tech Stack

- **Backend:** PHP (PDO + MySQL)
- **Frontend:** HTML, CSS, vanilla JavaScript
- **Payments:** Safaricom Daraja API (M-Pesa STK Push)
- **Server:** XAMPP (Apache + MySQL)

## 🚀 Setup

1. Clone the repo
2. Copy `.env.example` to `.env` and fill in your credentials
3. Import the database schema (`smart-plumbing/smart_plumbing.sql`) via phpMyAdmin
4. Start XAMPP (Apache + MySQL)
5. In a terminal, run: `ngrok http 80`
6. Update `MPESA_CALLBACK_URL` in `.env` with the ngrok URL
7. Visit `http://localhost/smart-plumbing/`

## 👨‍💻 Author

**Lawrence Makhanu** — [GitHub](https://github.com/law850)
