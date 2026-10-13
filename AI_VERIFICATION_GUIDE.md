# AI Evidence Verification Settings Guide

## Problem Fixed
AI verification was rejecting valid evidence files (like syllabi, teaching loads) even when they matched the requirements. This happened because the AI was being too strict or the API wasn't configured properly on the online server.

## Solution
Added a new **AI Verification Mode** setting that gives you 3 options:

### 1. **Disabled** ❌
- **What it does:** No AI verification at all
- **When to use:** 
  - You don't have a Gemini API key
  - AI is rejecting too many valid files
  - You want to temporarily disable AI checks
- **Result:** All file uploads are allowed without any AI check

### 2. **Advisory Only** ⚠️ (RECOMMENDED - Default)
- **What it does:** AI checks files and shows confidence scores, but NEVER blocks uploads
- **When to use:** 
  - You want AI feedback but don't want it blocking valid files
  - You trust your faculty to upload correct documents
  - You want the best of both worlds (AI help + no blocking)
- **Result:** 
  - AI checks every upload
  - Shows confidence percentage (e.g., "80% valid", "45% uncertain")
  - Always allows upload regardless of score
  - Faculty and deans can see AI feedback for manual review

### 3. **Strict Mode** 🚫
- **What it does:** AI blocks uploads if confidence is below 50%
- **When to use:** 
  - You need strict validation
  - You want to prevent wrong document types
  - You're willing to have some false rejections
- **Result:** 
  - AI checks every upload
  - Blocks upload if confidence < 50%
  - Shows error message with suggestions

## How to Change the Setting

1. Log in as **Admin**
2. Go to **Settings** page
3. Scroll down to **AI Evidence Verification** section
4. Select your preferred mode:
   - ☑️ **Disabled** - No AI checks
   - ☑️ **Advisory Only** (Recommended) - AI feedback but no blocking
   - ☑️ **Strict Mode** - AI blocks low-confidence uploads
5. Click **Save AI Settings**

## For Existing Installations (Online Server)

If you already have the system running online, run this SQL migration:

```sql
-- Run this in phpMyAdmin or MySQL console
INSERT INTO `system_config` (`config_key`, `config_value`, `updated_at`) 
VALUES ('ai_verification_mode', 'advisory', NOW())
ON DUPLICATE KEY UPDATE `config_key` = `config_key`;
```

Or import the file: `database/migration_add_ai_verification_mode.sql`

## Testing the Fix

1. **With Advisory Mode (Recommended):**
   - Upload a syllabus file for a "Syllabus Copy" requirement
   - You should see: "✅ AI Verified (85%)" or "⚠️ Uploaded (65% confidence)"
   - File is ALWAYS uploaded successfully
   - No more blocking errors!

2. **With Disabled Mode:**
   - Upload any file
   - You should see: "✅ File uploaded (AI verification disabled)"
   - No AI check happens at all

3. **With Strict Mode:**
   - Upload a completely wrong file (e.g., random image for syllabus requirement)
   - AI should block it with error message
   - Upload a matching file (e.g., "OOP-Syllabus.docx" for syllabus requirement)
   - Should be allowed with high confidence

## Recommendation for Online Server

Since you're experiencing issues with valid files being rejected:

**Set mode to "Advisory Only"** - This gives you:
- ✅ AI feedback and confidence scores
- ✅ No false rejections
- ✅ Faculty can upload valid files without problems
- ✅ Deans can still see AI confidence for manual review
- ✅ Best user experience

## Technical Details

### Files Modified:
1. `admin/settings.php` - Added AI mode radio buttons
2. `api/upload-evidence.php` - Check mode before blocking uploads
3. `faculty/ipcr-form.php` - Updated frontend messages
4. `database/ipcr_system.sql` - Added default config value
5. `database/migration_add_ai_verification_mode.sql` - Migration for existing installations

### How It Works:
- **Disabled:** Skips AI API call entirely
- **Advisory:** Runs AI check, saves result, but never blocks upload (success=true always)
- **Strict:** Runs AI check, blocks if confidence < 50% (success=false, ai_blocked=true)

## Support

If you still experience issues:
1. Check Settings page - verify mode is set correctly
2. Check browser console for errors (F12 → Console tab)
3. Check PHP error logs for API issues
4. Verify your Gemini API key is valid (if using Advisory or Strict mode)

---

**Date Fixed:** October 13, 2026  
**Issue:** AI rejecting valid evidence files  
**Solution:** Added configurable AI verification mode  
**Default Mode:** Advisory Only (recommended)
