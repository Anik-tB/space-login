#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Face Recognition Service
Compares a live face image with faces extracted from NID card photos.
"""

import sys
import json
import os
import traceback

# Import face recognition libraries with better error handling
try:
    import face_recognition
except ImportError as e:
    error_result = {
        'success': False,
        'message': f'Face recognition library not installed: {str(e)}. Please install face_recognition module with: pip install face_recognition',
        'match': False
    }
    print(json.dumps(error_result), file=sys.stdout)
    sys.stdout.flush()
    sys.exit(1)
except Exception as e:
    # Catch other import errors (like missing face_recognition_models)
    error_result = {
        'success': False,
        'message': f'Error importing face_recognition: {str(e)}. You may need to install face_recognition_models with: pip install git+https://github.com/ageitgey/face_recognition_models',
        'match': False
    }
    print(json.dumps(error_result), file=sys.stdout)
    sys.stdout.flush()
    sys.exit(1)

try:
    from PIL import Image
except ImportError:
    # PIL is optional, but good to have
    pass

import numpy as np

def validate_image_file(image_path):
    """
    Validate that the image file exists and is readable.
    """
    if not os.path.exists(image_path):
        return False, f"File does not exist: {image_path}"

    if not os.path.isfile(image_path):
        return False, f"Path is not a file: {image_path}"

    # Check file size (should be > 0)
    if os.path.getsize(image_path) == 0:
        return False, f"File is empty: {image_path}"

    # Try to open with PIL to validate it's a valid image
    try:
        from PIL import Image
        with Image.open(image_path) as img:
            img.verify()
    except Exception as e:
        return False, f"Invalid image file: {image_path}. Error: {str(e)}"

    return True, None

def extract_faces_from_image(image_path):
    """
    Extract face encodings from an image.
    Returns list of face encodings found in the image.
    """
    try:
        # Validate image first
        is_valid, error_msg = validate_image_file(image_path)
        if not is_valid:
            print(f"Warning: {error_msg}", file=sys.stderr)
            return []

        # Load image
        try:
            image = face_recognition.load_image_file(image_path)
        except Exception as e:
            print(f"Error loading image {image_path}: {str(e)}", file=sys.stderr)
            return []

        # Find face locations using HOG model (faster) or CNN model (more accurate)
        # Using HOG by default for better performance, but you can switch to 'cnn' if needed
        try:
            face_locations = face_recognition.face_locations(image, model='hog')
        except Exception as e:
            print(f"Error detecting faces in {image_path}: {str(e)}", file=sys.stderr)
            return []

        if not face_locations:
            return []

        # Get face encodings
        try:
            face_encodings = face_recognition.face_encodings(image, face_locations)
        except Exception as e:
            print(f"Error encoding faces in {image_path}: {str(e)}", file=sys.stderr)
            return []

        return face_encodings
    except Exception as e:
        print(f"Error processing image {image_path}: {str(e)}", file=sys.stderr)
        print(traceback.format_exc(), file=sys.stderr)
        return []

def compare_faces(known_encodings, unknown_encoding, tolerance=0.6):
    """
    Compare unknown face encoding with known encodings.
    Returns tuple: (match_found: bool, best_distance: float, confidence: str)
    """
    if not known_encodings or len(known_encodings) == 0:
        return False, float('inf'), 'none'

    if unknown_encoding is None or len(unknown_encoding) == 0:
        return False, float('inf'), 'none'

    best_distance = float('inf')
    match_found = False

    # Compare with all known faces
    for known_encoding in known_encodings:
        try:
            # Calculate face distance
            face_distance = face_recognition.face_distance([known_encoding], unknown_encoding)[0]

            # Track the best (lowest) distance
            if face_distance < best_distance:
                best_distance = face_distance

            # Check if distance is within tolerance
            if face_distance <= tolerance:
                match_found = True
        except Exception as e:
            print(f"Error comparing faces: {str(e)}", file=sys.stderr)
            continue

    # Determine confidence based on distance
    if match_found:
        if best_distance <= 0.4:
            confidence = 'very_high'
        elif best_distance <= 0.5:
            confidence = 'high'
        else:
            confidence = 'medium'
    else:
        if best_distance <= 0.7:
            confidence = 'low'
        else:
            confidence = 'very_low'

    return match_found, best_distance, confidence

def main():
    # Ensure only JSON is printed to stdout (errors go to stderr)
    if len(sys.argv) != 4:
        result = {
            'success': False,
            'message': 'Invalid number of arguments. Expected: nid_front_path nid_back_path face_image_path',
            'match': False
        }
        print(json.dumps(result), file=sys.stdout)
        sys.stdout.flush()
        sys.exit(1)

    nid_front_path = sys.argv[1]
    nid_back_path = sys.argv[2]
    face_image_path = sys.argv[3]

    # Check if files exist
    if not os.path.exists(nid_front_path):
        result = {
            'success': False,
            'message': 'NID front image not found',
            'match': False
        }
        print(json.dumps(result), file=sys.stdout)
        sys.stdout.flush()
        sys.exit(1)

    if not os.path.exists(nid_back_path):
        result = {
            'success': False,
            'message': 'NID back image not found',
            'match': False
        }
        print(json.dumps(result), file=sys.stdout)
        sys.stdout.flush()
        sys.exit(1)

    if not os.path.exists(face_image_path):
        result = {
            'success': False,
            'message': 'Face image not found',
            'match': False
        }
        print(json.dumps(result), file=sys.stdout)
        sys.stdout.flush()
        sys.exit(1)

    try:
        # Extract faces from NID photos
        print("Extracting faces from NID front photo...", file=sys.stderr)
        nid_front_faces = extract_faces_from_image(nid_front_path)

        print("Extracting faces from NID back photo...", file=sys.stderr)
        nid_back_faces = extract_faces_from_image(nid_back_path)

        # Combine all NID face encodings
        all_nid_faces = nid_front_faces + nid_back_faces

        if not all_nid_faces or len(all_nid_faces) == 0:
            result = {
                'success': False,
                'message': 'No faces found in NID photos. Please ensure your NID photos clearly show your face and are well-lit.',
                'match': False,
                'faces_found_in_nid': len(nid_front_faces) + len(nid_back_faces)
            }
            print(json.dumps(result), file=sys.stdout)
            sys.stdout.flush()
            sys.exit(0)

        print(f"Found {len(all_nid_faces)} face(s) in NID photos", file=sys.stderr)

        # Extract face from live image
        print("Extracting face from captured image...", file=sys.stderr)
        live_face_encodings = extract_faces_from_image(face_image_path)

        if not live_face_encodings or len(live_face_encodings) == 0:
            result = {
                'success': False,
                'message': 'No face detected in the captured image. Please ensure your face is clearly visible, well-lit, and try again.',
                'match': False
            }
            print(json.dumps(result), file=sys.stdout)
            sys.stdout.flush()
            sys.exit(0)

        print(f"Found {len(live_face_encodings)} face(s) in captured image", file=sys.stderr)

        # Use the first (and usually only) face from live image
        live_face_encoding = live_face_encodings[0]

        # Compare faces with a reasonable tolerance
        # Tolerance 0.6 is the default and works well for most cases
        print("Comparing faces...", file=sys.stderr)
        match_found, distance, confidence = compare_faces(all_nid_faces, live_face_encoding, tolerance=0.6)

        if match_found:
            result = {
                'success': True,
                'message': 'Face verification successful! Your face matches the NID photos.',
                'match': True,
                'confidence': confidence,
                'distance': round(distance, 4)
            }
        else:
            result = {
                'success': True,
                'message': 'Face verification failed. The captured face does not match the faces in your NID photos. Please ensure you are using your own NID card and that the photos are clear, then try again.',
                'match': False,
                'confidence': confidence,
                'distance': round(distance, 4)
            }

        print(json.dumps(result), file=sys.stdout)
        sys.stdout.flush()
        sys.exit(0)

    except Exception as e:
        error_msg = f'Error during face recognition: {str(e)}'
        print(f"Exception: {error_msg}", file=sys.stderr)
        print(traceback.format_exc(), file=sys.stderr)

        result = {
            'success': False,
            'message': error_msg,
            'match': False
        }
        print(json.dumps(result), file=sys.stdout)
        sys.stdout.flush()
        sys.exit(1)

if __name__ == '__main__':
    main()

