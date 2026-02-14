@echo off
echo ========================================
echo Face Recognition Setup for Windows
echo Python 3.12.2 Installation Script
echo ========================================
echo.

REM Check if Python is installed
python --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Python is not found in PATH!
    echo Please make sure Python 3.12.2 is installed and added to PATH.
    echo.
    echo Trying 'py' command instead...
    py --version >nul 2>&1
    if errorlevel 1 (
        echo ERROR: Python is not accessible via 'py' command either.
        echo Please install Python 3.12.2 and add it to your PATH.
        pause
        exit /b 1
    ) else (
        set PYTHON_CMD=py
        echo Found Python via 'py' command.
    )
) else (
    set PYTHON_CMD=python
    echo Found Python via 'python' command.
)

echo.
echo Step 1: Upgrading pip...
%PYTHON_CMD% -m pip install --upgrade pip
echo.

echo Step 2: Installing Pillow (image processing)...
%PYTHON_CMD% -m pip install Pillow
echo.

echo Step 3: Installing numpy (numerical computing)...
%PYTHON_CMD% -m pip install numpy
echo.

echo Step 4: Installing dlib...
echo NOTE: This may take a few minutes. If it fails, you may need:
echo   - Visual Studio Build Tools with C++ workload, OR
echo   - Download a pre-built wheel from GitHub
echo.
%PYTHON_CMD% -m pip install dlib
if errorlevel 1 (
    echo.
    echo WARNING: dlib installation failed!
    echo.
    echo You have two options:
    echo   1. Install Visual Studio Build Tools (recommended)
    echo      Download: https://visualstudio.microsoft.com/downloads/
    echo      Select "Desktop development with C++" workload
    echo.
    echo   2. Use a pre-built wheel:
    echo      Visit: https://github.com/Murtaza-Saeed/Dlib-Precompiled-Wheels-for-Python-on-Windows-x64-Easy-Installation
    echo      Download dlib-19.24.99-cp312-cp312-win_amd64.whl
    echo      Then run: pip install dlib-19.24.99-cp312-cp312-win_amd64.whl
    echo.
    pause
    exit /b 1
)
echo.

echo Step 5: Installing face-recognition library...
%PYTHON_CMD% -m pip install face-recognition
echo.

echo Step 6: Verifying installation...
%PYTHON_CMD% -c "import face_recognition; print('SUCCESS: Face recognition installed correctly!')"
if errorlevel 1 (
    echo ERROR: Verification failed!
    pause
    exit /b 1
)

echo.
echo ========================================
echo Installation Complete!
echo ========================================
echo.
echo Next steps:
echo 1. Make sure PHP can find Python (check verify_face.php)
echo 2. Test the installation by running:
echo    python face_recognition_service.py test1.jpg test2.jpg test3.jpg
echo 3. See WINDOWS_SETUP_GUIDE.md for detailed instructions
echo.
pause

