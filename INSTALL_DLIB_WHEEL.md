# How to Install dlib Wheel File on Windows

## Step-by-Step Instructions

### Step 1: Download the Wheel File

1. **Go to the GitHub repository:**
   - Visit: https://github.com/Murtaza-Saeed/Dlib-Precompiled-Wheels-for-Python-on-Windows-x64-Easy-Installation
   - Or search for "dlib python 3.12 wheel windows" on GitHub

2. **Download the correct file:**
   - Look for: `dlib-19.24.99-cp312-cp312-win_amd64.whl`
   - This is for Python 3.12 (cp312) on Windows 64-bit
   - Click on the file, then click "Download" or "Download raw file"

3. **Save the file:**
   - You can save it **anywhere** on your computer
   - **Recommended:** Save it in your project folder: `C:\xampp\htdocs\space-login\`
   - Or save it in your Downloads folder: `C:\Users\YourUsername\Downloads\`

### Step 2: Open PowerShell

1. Press `Windows Key + X`
2. Select "Windows PowerShell" or "Terminal"
3. Or search for "PowerShell" in the Start menu

### Step 3: Navigate to Where You Saved the File

**If you saved it in your project folder:**
```powershell
cd C:\xampp\htdocs\space-login
```

**If you saved it in Downloads:**
```powershell
cd C:\Users\YourUsername\Downloads
```
(Replace `YourUsername` with your actual Windows username)

**To find your username:**
```powershell
echo $env:USERNAME
```

### Step 4: Install the Wheel File

Run this command (replace with the exact filename if different):

```powershell
pip install dlib-19.24.99-cp312-cp312-win_amd64.whl
```

**If the file is in a different location, use the full path:**
```powershell
pip install "C:\Users\YourUsername\Downloads\dlib-19.24.99-cp312-cp312-win_amd64.whl"
```

### Step 5: Verify Installation

Check if dlib installed correctly:

```powershell
python -c "import dlib; print('dlib version:', dlib.__version__)"
```

You should see: `dlib version: 19.24.99` (or similar)

### Step 6: Install Other Dependencies

Now install the remaining packages:

```powershell
pip install face-recognition Pillow numpy
```

### Step 7: Final Verification

Test if everything works:

```powershell
python -c "import face_recognition, dlib, PIL, numpy; print('All packages installed successfully!')"
```

## Quick Command Summary

```powershell
# Navigate to project folder
cd C:\xampp\htdocs\space-login

# Install dlib wheel (if file is in project folder)
pip install dlib-19.24.99-cp312-cp312-win_amd64.whl

# Or if file is in Downloads
pip install "C:\Users\YourUsername\Downloads\dlib-19.24.99-cp312-cp312-win_amd64.whl"

# Install other dependencies
pip install face-recognition Pillow numpy

# Verify
python -c "import face_recognition; print('Success!')"
```

## Troubleshooting

### Issue: "pip install dlib-19.24.99-cp312-cp312-win_amd64.whl" says file not found

**Solution:**
- Make sure you're in the correct directory where the file is saved
- Or use the full path to the file
- Check the exact filename (it might be slightly different)

### Issue: "No such file or directory"

**Solution:**
- Verify the file was downloaded completely
- Check the file extension is `.whl` (not `.whl.txt`)
- Make sure you're using the correct path

### Issue: "pip is not recognized"

**Solution:**
- Use: `python -m pip install` instead of `pip install`
- Or: `py -m pip install`

### Issue: "ERROR: dlib-19.24.99-cp312-cp312-win_amd64.whl is not a supported wheel on this platform"

**Solution:**
- Make sure you downloaded the correct file for Python 3.12
- Check your Python version: `python --version`
- The file must match: `cp312` = Python 3.12, `win_amd64` = Windows 64-bit

## Alternative: Install from URL (if available)

If the GitHub repository provides a direct download link, you can install directly:

```powershell
pip install https://github.com/user/repo/raw/main/dlib-19.24.99-cp312-cp312-win_amd64.whl
```

(Replace with the actual download URL)

