# Fix: ModuleNotFoundError for face_recognition

## The Problem

Packages installed successfully, but Python can't find them. This usually means:
- Multiple Python installations
- Packages installed in different Python environment
- Using wrong Python interpreter

## Solution Steps

### Step 1: Check Which Python You're Using

Run these commands in PowerShell:

```powershell
# Check Python version
python --version

# Check where Python is located
where python

# Check if packages are installed
python -m pip list | findstr face
```

### Step 2: Verify Package Installation

Check if face-recognition is actually installed:

```powershell
python -m pip show face-recognition
```

If it shows "WARNING: Package(s) not found", the package isn't installed in this Python.

### Step 3: Reinstall Using python -m pip

Try installing again using the explicit Python module:

```powershell
python -m pip install face-recognition Pillow numpy
```

### Step 4: Test Import Again

```powershell
python -c "import face_recognition; print('Success!')"
```

### Step 5: If Still Not Working - Check Multiple Python Installations

You might have multiple Python versions. Try:

```powershell
# Check all Python installations
py --list

# Try with py launcher
py -3.12 -m pip install face-recognition Pillow numpy
py -3.12 -c "import face_recognition; print('Success!')"
```

### Step 6: Alternative - Use Full Python Path

Find your Python path from the installation output:
`C:\Users\Hp\AppData\Local\Programs\Python\Python312\python.exe`

Then use it directly:

```powershell
C:\Users\Hp\AppData\Local\Programs\Python\Python312\python.exe -m pip install face-recognition Pillow numpy
C:\Users\Hp\AppData\Local\Programs\Python\Python312\python.exe -c "import face_recognition; print('Success!')"
```

## Quick Fix Commands

Try these in order:

```powershell
# 1. Reinstall with explicit Python module
python -m pip install --force-reinstall face-recognition Pillow numpy

# 2. Test import
python -c "import face_recognition; print('✅ Success!')"

# 3. If that fails, try py launcher
py -3.12 -m pip install face-recognition Pillow numpy
py -3.12 -c "import face_recognition; print('✅ Success!')"
```

## Verify All Packages

After fixing, verify all packages:

```powershell
python -c "import face_recognition, dlib, PIL, numpy; print('✅ All packages working!')"
```

## Important for PHP

Once Python import works, make sure `verify_face.php` uses the same Python interpreter that has the packages installed!

