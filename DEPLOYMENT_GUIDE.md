# 🚀 IPCR SYSTEM - DEPLOYMENT GUIDE

## How to Move This System to Another PC

This guide will help you deploy the IPCR system to a new computer or server.

---

## 📦 What to Copy

Copy the entire **`ipcr-system`** folder to the new PC. That's it!

**Folder Structure:**
```
ipcr-system/
├── admin/              (Admin pages)
├── api/                (Backend APIs)
├── assets/             (CSS, JS, images)
├── config/             (Configuration files)
├── database/           (Database setup files)
├── dean/               (Dean pages)
├── exports/            (Print/export pages)
├── faculty/            (Faculty pages)
├── includes/           (Shared PHP files)
├── secretary/          (Secretary pages)
├── uploads/            (Uploaded evidence files)
├── vendor/             (Composer dependencies)
├── index.php           (Login page)
├── dashboard.php       (Main dashboard)
└── ... (other files)
```

---

## 🖥️ Requirements on New PC

The new PC must have:

✅ **XAMPP** (or any Apache + MySQL + PHP stack)
   - Apache Web Server
   - MySQL Database
   - PHP 7.4 or higher

✅ **Web Browser**
   - Chrome, Firefox, Edge, etc.

---

## 📋 Step-by-Step Deployment

### Step 1: Install XAMPP (if not installed)

1. Download XAMPP: https://www.apachefriends.org/
2. Install to default location (usually `C:\xampp`)
3. Start Apache and MySQL from XAMPP Control Panel

### Step 2: Copy the System Files

**Option A: USB/External Drive**
```
1. Copy folder: C:\xampp\htdocs\New folder\ipcr-system
2. Paste to new PC: C:\xampp\htdocs\ipcr-system
```

**Option B: Network/Cloud**
```
1. Zip the ipcr-system folder
2. Upload to Google Drive/OneDrive
3. Download on new PC
4. Extract to: C:\xampp\htdocs\ipcr-system
```

**Option C: Direct Copy (if on same network)**
```
\\OldPC\SharedFolder\ipcr-system → C:\xampp\htdocs\ipcr-system
```

### Step 3: Import the Database

1. **Open phpMyAdmin** on new PC:
   ```
   http://localhost/phpmyadmin
   ```

2. **Import the database:**
   - Click "Import" tab
   - Click "Choose File"
   - Select: `C:\xampp\htdocs\ipcr-system\database\COMPLETE_DATABASE.sql`
   - Click "Go"
   - Wait for completion

3. **Verify:**
   - Database `ipcr_system` should appear in left sidebar
   - Should have 11 tables
   - Should have 9 departments
   - Should have 2 default users

### Step 4: Configure the System

**Check database configuration:**

Open: `ipcr-system/config/db.php`

Make sure it has:
```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'ipcr_system');
define('DB_USER', 'root');
define('DB_PASS', '');  // Empty for XAMPP default
```

**If your MySQL has a password:**
```php
define('DB_PASS', 'your_mysql_password');
```

### Step 5: Set Folder Permissions

Make sure the `uploads/` folder is writable:

**Windows:**
```
Right-click on ipcr-system/uploads
→ Properties
→ Security tab
→ Edit
→ Add "Users" group
→ Allow "Full Control"
→ Apply
```

**Linux/Mac:**
```bash
chmod 755 -R ipcr-system/
chmod 777 ipcr-system/uploads/
```

### Step 6: Access the System

1. **Open browser**
2. **Go to:**
   ```
   http://localhost/ipcr-system
   ```
3. **Login with default admin:**
   - Username: `ADMIN001`
   - Password: `Admin@2024`

4. **Success!** ✅

---

## 🔧 Configuration Files to Check

### 1. Database Connection
**File:** `config/db.php`
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'ipcr_system');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### 2. Base URL
**File:** `includes/header.php`

Find and update if needed:
```php
define('BASE_URL', 'http://localhost/ipcr-system');
```

**For production server:**
```php
define('BASE_URL', 'http://yourserver.com/ipcr-system');
```

### 3. Email Configuration (Optional)
**File:** `config/email.php`

Configure if you want to send actual emails:
```php
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'your-email@gmail.com');
define('SMTP_PASS', 'your-app-password');
define('SMTP_PORT', 587);
```

---

## 📤 Migrating WITH Existing Data

If you already have data (users, IPCRs, etc.) and want to move it:

### Step 1: Export Database from Old PC

1. Open phpMyAdmin on OLD PC
2. Select `ipcr_system` database
3. Click "Export" tab
4. Choose "Quick" method
5. Format: SQL
6. Click "Go"
7. Save file as: `ipcr_system_backup_YYYY-MM-DD.sql`

### Step 2: Copy Both Files and Database

Copy these to new PC:
```
✅ ipcr-system folder (all files)
✅ ipcr_system_backup_YYYY-MM-DD.sql (database export)
```

### Step 3: Import on New PC

1. Copy `ipcr-system` folder to `C:\xampp\htdocs\`
2. Import the backup SQL file in phpMyAdmin
3. Done! All data migrated ✅

---

## 🌐 Deploying to Production Server

### For Online/Public Server:

1. **Copy files via FTP:**
   ```
   Upload ipcr-system folder to: /public_html/ipcr-system/
   ```

2. **Create database:**
   ```sql
   CREATE DATABASE ipcr_system;
   ```

3. **Import SQL file via cPanel/phpMyAdmin**

4. **Update config files:**
   - `config/db.php` - Update credentials
   - `includes/header.php` - Update BASE_URL

5. **Set folder permissions:**
   ```bash
   chmod 755 -R /public_html/ipcr-system/
   chmod 777 /public_html/ipcr-system/uploads/
   ```

6. **SSL Certificate (Recommended):**
   - Install SSL certificate
   - Update BASE_URL to `https://`

---

## ✅ Post-Deployment Checklist

After deployment, verify:

- [ ] Can access login page
- [ ] Can login with ADMIN001 / Admin@2024
- [ ] Dashboard loads correctly
- [ ] Can navigate to all pages
- [ ] Can add new users
- [ ] Can create rating periods
- [ ] Can upload files (test evidence upload)
- [ ] Email notifications work (if configured)
- [ ] Printing works (test IPCR print)
- [ ] All degree programs show in dropdowns

---

## 🚨 Common Issues & Solutions

### Issue 1: "Cannot connect to database"
**Solution:**
- Check MySQL is running
- Verify database credentials in `config/db.php`
- Make sure database `ipcr_system` exists

### Issue 2: "Page not found" or 404 errors
**Solution:**
- Check `.htaccess` file exists in ipcr-system folder
- Enable mod_rewrite in Apache
- Update BASE_URL in `includes/header.php`

### Issue 3: "Cannot upload files"
**Solution:**
- Check `uploads/` folder exists
- Set folder permissions to 777 (writable)
- Check PHP upload settings in `php.ini`:
  ```ini
  upload_max_filesize = 50M
  post_max_size = 50M
  ```

### Issue 4: "Blank white page"
**Solution:**
- Enable error display in `php.ini`:
  ```ini
  display_errors = On
  error_reporting = E_ALL
  ```
- Check Apache error log: `C:\xampp\apache\logs\error.log`

### Issue 5: "Session errors"
**Solution:**
- Clear browser cache and cookies
- Check PHP sessions folder is writable
- Restart Apache

---

## 📊 Database Migration Summary

| What | File | Action |
|------|------|--------|
| Fresh Install | `COMPLETE_DATABASE.sql` | Import this |
| With Data | `ipcr_system_backup.sql` | Export from old, import to new |
| Tables | 11 tables | Auto-created |
| Initial Data | Departments + 2 users | Included |

---

## 🔒 Security Checklist for Production

If deploying to public server:

- [ ] Change default admin password
- [ ] Change default dean password
- [ ] Update database credentials (not 'root'/'')
- [ ] Set strong MySQL password
- [ ] Enable HTTPS/SSL
- [ ] Restrict folder permissions (not 777 in production)
- [ ] Disable directory listing
- [ ] Keep `config/` folder secure
- [ ] Enable PHP security settings
- [ ] Regular backups scheduled

---

## 💾 Backup Strategy

### Daily Backup (Recommended):
```
1. Export database via phpMyAdmin
2. Download backup SQL file
3. Copy uploads/ folder
4. Store in safe location (external drive/cloud)
```

### Automated Backup:
Create a batch file `backup.bat`:
```batch
@echo off
set DATE=%date:~-4,4%%date:~-10,2%%date:~-7,2%
"C:\xampp\mysql\bin\mysqldump.exe" -u root ipcr_system > "D:\Backups\ipcr_%DATE%.sql"
xcopy "C:\xampp\htdocs\ipcr-system\uploads" "D:\Backups\uploads_%DATE%\" /E /I
echo Backup completed!
```

---

## 📞 Support

If you encounter issues during deployment:

1. Check this guide first
2. Review error logs
3. Verify all requirements
4. Test on localhost first before production

---

## 🎯 Quick Deployment Summary

**For New Installation:**
```
1. Install XAMPP
2. Copy ipcr-system folder
3. Import COMPLETE_DATABASE.sql
4. Access: http://localhost/ipcr-system
5. Login: ADMIN001 / Admin@2024
```

**For Migration (with data):**
```
1. Export database from old PC
2. Copy ipcr-system folder + SQL backup
3. Install XAMPP on new PC
4. Copy folder to htdocs
5. Import SQL backup
6. Access and verify
```

**That's it!** Your system is now portable and ready to deploy anywhere! 🚀

---

**Date:** October 12, 2026  
**Version:** 2.0  
**Status:** ✅ Production Ready  
