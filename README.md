# 🎓 IPCR Management System

**Individual Performance Commitment and Review (IPCR) Management System** for academic institutions. Streamlines the faculty evaluation process from submission to approval.

**Version:** 2.0 | **Status:** ✅ Production Ready | **Date:** October 2026

---

## ⚡ Quick Start (3 Steps)

### 1. Copy Files → 2. Import Database → 3. Login

```
📁 Copy to: C:\xampp\htdocs\ipcr-system
💾 Import: database/COMPLETE_DATABASE.sql (via phpMyAdmin)
🌐 Access: http://localhost/ipcr-system
🔑 Login: ADMIN001 / Admin@2024
```

**See `QUICK_START.txt` for detailed step-by-step instructions!**

---

## 📦 What You Need

- XAMPP (or Apache + MySQL + PHP 7.4+)
- Modern web browser
- 5 minutes of your time

---

## 🎯 What's Included

✅ **Complete System:** Ready to use out of the box
✅ **Pre-loaded Data:** 9 degree programs, 12 sample users
✅ **Single Database File:** Just import and go
✅ **Clean Structure:** No clutter, production-ready
✅ **Full Documentation:** Everything you need to know

---

## 👥 Default Accounts

### Admin Access
```
Username: ADMIN001
Password: Admin@2024
Access: Full system control (users, periods, templates, reports)
```

### Dean Access
```
Username: DEAN001
Password: Dean@2024
Access: Faculty management, IPCR review and rating
```

### Faculty Access
```
Usernames: CE-001, EE-001, ARCH-001, ICT-001, ICT-002, ICT-003, 
           IT-001, ED-001, ED-002, and more (10 total)
Password: Faculty@2024 (same for all)
Access: IPCR form submission and tracking
```

**⚠️ IMPORTANT:** Change all default passwords before production use!

---

## 🏫 Pre-configured Degree Programs

1. **Administration** (ADM)
2. **Bachelor of Science in Civil Engineering** (BSCE)
3. **Bachelor of Science in Electrical Engineering** (BSEE)
4. **Bachelor of Science in Architecture** (BSArch)
5. **Bachelor of Science in Information Technology** (BSIT)
6. **Bachelor of Science in Industrial Technology** (BSIT-Ind)
7. **Bachelor of Science in Engineering Technology** (BSET)
8. **Bachelor of Secondary Education** (BSEd)
9. **Bachelor of Elementary Education** (BEEd)

**Total:** 9 programs ready to use. Add more from Admin → Departments.

---

## 🎯 Key Features

### 👨‍💼 For Administrators
- ✅ Complete user management (add/edit/delete faculty, deans)
- ✅ Department and degree program management
- ✅ Rating period creation and management
- ✅ System-wide reports and analytics
- ✅ Audit logs and activity tracking
- ✅ IPCR template customization
- ✅ Submission tracking dashboard

### 👔 For Deans
- ✅ Manage faculty under their department
- ✅ Review and rate submitted IPCRs
- ✅ Verify uploaded evidence
- ✅ Department-specific reports
- ✅ Submission tracking by faculty
- ✅ Return IPCRs for revision with remarks

### 🎓 For Faculty
- ✅ Create and submit IPCR forms
- ✅ Upload supporting evidence/documents
- ✅ Track submission and review status
- ✅ View ratings and feedback
- ✅ Print submitted IPCRs
- ✅ Revision and resubmission capability
- ✅ Evaluation history tracking

---

## 📂 System Structure

```
ipcr-system/
├── admin/              Admin pages
├── api/                Backend APIs
├── assets/             CSS, JS, images
├── config/             Configuration
├── database/           Database setup
├── dean/               Dean pages
├── exports/            Print/export
├── faculty/            Faculty pages
├── includes/           Shared PHP
├── secretary/          Secretary pages
├── uploads/            Evidence files
├── vendor/             Dependencies
├── index.php           Login page
└── dashboard.php       Main dashboard
```

---

## 🎓 Degree Programs

The system includes 9 pre-configured degree programs:

1. Administration
2. Bachelor of Science in Civil Engineering (BSCE)
3. Bachelor of Science in Electrical Engineering (BSEE)
4. Bachelor of Science in Architecture (BSArch)
5. Bachelor of Science in Information Technology (BSIT)
6. Bachelor of Science in Industrial Technology (BSIT-Ind)
7. Bachelor of Science in Engineering Technology (BSET)
8. Bachelor of Secondary Education (BSEd)
9. Bachelor of Elementary Education (BEEd)

---

## 🔧 Configuration

### Database Settings
**File:** `config/db.php`
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'ipcr_system');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### Base URL
**File:** `includes/header.php`
```php
define('BASE_URL', 'http://localhost/ipcr-system');
```

Update for production:
```php
define('BASE_URL', 'https://your-domain.com/ipcr-system');
```

---

## 📖 User Guide

### First Time Setup:

1. **Login as Admin**
   - Username: ADMIN001
   - Password: Admin@2024

2. **Change Default Passwords**
   - Go to: Admin → Users
   - Edit each account
   - Set strong passwords

3. **Add Your Faculty**
   - Go to: Admin → Users
   - Click "Add User"
   - Assign to appropriate department

4. **Create Rating Period**
   - Go to: Admin → Rating Periods
   - Click "New Period"
   - Set dates and deadlines

5. **Start Using!**
   - Faculty can now create IPCRs
   - Dean can review and rate
   - Admin can monitor progress

---

## 🔐 Security Notes

**For Production Deployment:**

- [ ] Change all default passwords
- [ ] Update database credentials
- [ ] Enable HTTPS/SSL
- [ ] Set folder permissions (755 for folders, 644 for files)
- [ ] Secure uploads folder (777 in development, 755 in production)
- [ ] Enable PHP security settings
- [ ] Disable error display
- [ ] Regular backups

---

## 📁 Important Files

| File | Purpose |
|------|---------|
| `database/COMPLETE_DATABASE.sql` | Master database file |
| `database/README_SETUP.txt` | Setup instructions |
| `DEPLOYMENT_GUIDE.md` | Complete deployment guide |
| `DEPLOYMENT_CHECKLIST.txt` | Deployment checklist |
| `config/db.php` | Database configuration |
| `includes/header.php` | Base URL configuration |

---

## 🆘 Troubleshooting

### Cannot Login
- Check database is imported
- Verify credentials
- Clear browser cache

### Page Not Found
- Check folder is in htdocs
- Verify BASE_URL in header.php
- Enable mod_rewrite

### Cannot Upload Files
- Check uploads/ folder exists
- Set folder permissions (777)
- Check PHP upload settings

### Blank Page
- Check Apache error log
- Enable error display
- Verify all files copied

---

## 📊 Database Schema

- **11 Tables**
- **9 Departments**
- **12 Sample Users**
- **Audit logging**
- **Session management**

---

## 💾 Backup

### Regular Backups (Recommended):

1. **Database:**
   ```
   phpMyAdmin → Export → Go
   ```

2. **Files:**
   ```
   Copy uploads/ folder
   ```

3. **Store safely:**
   ```
   External drive or cloud storage
   ```

---

## 🌐 Deployment

### To New PC:
1. Copy ipcr-system folder
2. Import COMPLETE_DATABASE.sql
3. Update config if needed
4. Access and test

### To Production Server:
1. Upload via FTP
2. Create database
3. Import SQL
4. Update config files
5. Set permissions
6. Enable SSL

See `DEPLOYMENT_GUIDE.md` for detailed instructions.

---

## 📞 Support

For issues or questions:
1. Check `DEPLOYMENT_GUIDE.md`
2. Review `DEPLOYMENT_CHECKLIST.txt`
3. Check database setup in `database/README_SETUP.txt`

---

## 🎉 Version

**Version:** 2.0  
**Date:** October 2026  
**Status:** Production Ready  

---

## 📝 License

Proprietary - For authorized use only

---

## ✅ Post-Setup Checklist

After installation:

- [ ] Can access login page
- [ ] Can login as admin
- [ ] Dashboard loads correctly
- [ ] Can navigate all pages
- [ ] Can add users
- [ ] Can create periods
- [ ] Can upload files
- [ ] Printing works
- [ ] All dropdowns show correct data

---

**Ready to deploy!** 🚀

For detailed deployment instructions, see:
- `DEPLOYMENT_GUIDE.md` - Complete guide
- `DEPLOYMENT_CHECKLIST.txt` - Quick checklist
- `database/README_SETUP.txt` - Database setup

---

© 2026 IPCR Management System
