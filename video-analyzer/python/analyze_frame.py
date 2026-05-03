import os
import sys
import json
import uuid
import numpy as np
import requests
import cv2
from deepface import DeepFace
from sklearn.metrics.pairwise import cosine_similarity

# Configuration
API_URL = "http://localhost:8000/api/known-faces"
CURRENT_UNKNOWN_API_URL = "http://localhost:8000/api/current-unknown"
TMP_DIR = "/tmp/analyzed-faces"
DEBUG_DIR = "/tmp/face-debug"
DEBUG_ENABLED = True
MATCH_THRESHOLD = 0.6  # Accept faces with similarity > MATCH_THRESHOLD
MIN_BOX_RATIO = 0.01    # Ignore faces smaller than 1% of image
MAX_BOX_RATIO = 0.9     # Ignore faces larger than 90% of image (likely full frame)

def load_known_faces():
    """
    Load known faces from the API.
    """
    try:
        response = requests.get(API_URL)
        response.raise_for_status()
        return response.json()
    except Exception as e:
        print(f"[⚠️] Failed to fetch known faces: {e}", file=sys.stderr)
        return []

def extract_faces(frame_path):
    """
    Extract faces from the provided image using DeepFace.
    """
    try:
        return DeepFace.extract_faces(
            img_path=frame_path,
            detector_backend="retinaface",
            enforce_detection=False,
            align=True
        )
    except Exception as e:
        print(f"[⚠️] Face extraction failed: {e}", file=sys.stderr)
        return []

def get_embedding(face_img_path):
    """
    Generate the embedding for a given face image using DeepFace.
    """
    try:
        result = DeepFace.represent(
            img_path=face_img_path,
            model_name="Facenet",  # Use Facenet by default
            enforce_detection=False
        )
        emb = result[0]["embedding"]
        if np.linalg.norm(emb) < 4:  # Filter out embeddings with very low norms
            print("[🚫] Embedding discarded: too low norm", file=sys.stderr)
            return None
        return emb
    except Exception as e:
        print(f"[🚫] Embedding error: {e}", file=sys.stderr)
        return None

def analyze_face_attributes(face_img_path):
    """
    Analyze attributes like age, gender, and emotion for a given face.
    """
    try:
        result = DeepFace.analyze(
            img_path=face_img_path,
            actions=['age', 'gender', 'emotion'],
            enforce_detection=True
        )[0]
        return result.get("age", 0), result.get("gender", "unknown"), result.get("dominant_emotion", "unknown")
    except Exception as e:
        print(f"[⚠️] Attribute analysis failed: {e}", file=sys.stderr)
        return 0, "unknown", "unknown"

def match_known_face(embedding, known_faces):
    """
    Matches the input embedding against all known faces and returns the best match
    if the similarity is above the threshold.
    """
    best_match = None
    best_similarity = -1  # Start with the lowest possible value for similarity
    matched_face = None   # To store the matched face data (if any)

    for known in known_faces:
        # Iterate through up to 3 embeddings of the known person
        for idx, known_embedding in enumerate (known["embeddings"]):
            known_embedding_np = np.array(known_embedding).reshape(1, -1)
            similarity = cosine_similarity([embedding], known_embedding_np)[0][0]

            # Update best match if similarity is higher
            if similarity > best_similarity:
                best_similarity = similarity
                best_match = known["label"]
                matched_face = idx  # Save the face path here if available

    # Only return the best match if its similarity exceeds the threshold
    if best_similarity > MATCH_THRESHOLD:
        return best_match, matched_face, best_similarity
    else:
        return None, None, None  # No match found

def save_face_temp(face_img):
    """
    Save a cropped face to a temporary directory for further processing.
    """
    os.makedirs(TMP_DIR, exist_ok=True)
    if face_img.dtype != np.uint8:
        face_img = (face_img * 255).clip(0, 255).astype(np.uint8)
    face_img = cv2.cvtColor(face_img, cv2.COLOR_RGB2BGR)
    path = os.path.join(TMP_DIR, f"{uuid.uuid4()}.jpg")
    cv2.imwrite(path, face_img)
    return path

def save_debug_crop(face_img, label):
    """
    Save a face crop for debugging purposes into a labeled directory.
    """
    os.makedirs(DEBUG_DIR, exist_ok=True)
    if face_img.dtype != np.uint8:
        face_img = (face_img * 255).clip(0, 255).astype(np.uint8)
    face_img = cv2.cvtColor(face_img, cv2.COLOR_RGB2BGR)
    path = os.path.join(DEBUG_DIR, f"{label}_{uuid.uuid4()}.jpg")
    cv2.imwrite(path, face_img)
    return path

def is_reasonable_face(face_data, frame_shape):
    """
    Checks if the detected face is reasonable for recognition by size check.
    """
    region = face_data.get("facial_area")
    if not region:
        return False

    img_h, img_w = frame_shape[:2]
    w = region["w"]
    h = region["h"]
    box_area = w * h
    img_area = img_h * img_w
    area_ratio = box_area / img_area

    if area_ratio < MIN_BOX_RATIO:
        print(f"[🚫] Face too small (area ratio: {area_ratio:.3f})", file=sys.stderr)
        return False
    if area_ratio > MAX_BOX_RATIO:
        print(f"[🚫] Face too big (area ratio: {area_ratio:.3f}) — likely full image", file=sys.stderr)
        return False

    return True

def main():
    if len(sys.argv) < 2:
        print("Usage: python analyze_frame.py <frame.jpg>")
        sys.exit(1)

    frame_path = sys.argv[1]
    known_faces = load_known_faces()

    results = []

    # Load the input frame
    frame_img = cv2.imread(frame_path)
    if frame_img is None:
        print(f"[❌] Could not read image: {frame_path}")
        sys.exit(1)

    # Extract faces from the input image
    faces = extract_faces(frame_path)

    if not faces:
        print("[]")  # No faces detected
        return

    # Iterate over detected faces

    current = -1
    try:
        response = requests.get(CURRENT_UNKNOWN_API_URL)
        response.raise_for_status()

        # Parse the JSON response
        data = response.json()
        current = data.get("current", -1)  # Default to 0 if "current" key is not present

    except Exception as e:
        print(f"[⚠️] Failed to fetch known faces: {e}", file=sys.stderr)
        return []

    for idx, face_data in enumerate(faces):
        if not is_reasonable_face(face_data, frame_img.shape):
            continue  # Skip faces that are too small/large

        # Save the face for further processing
        face_img = face_data.get("face")
        if DEBUG_ENABLED:
            debug_path = save_debug_crop(face_img, f"raw_{idx}")

        # Generate the face embedding
        emb = get_embedding(debug_path)
        if emb is None:
            continue  # Skip if embedding could not be generated

        # Analyze face attributes
        age, gender, emotion = analyze_face_attributes(debug_path)

        # Save the cropped face to a temporary location
        saved_path = save_face_temp(face_img)

        # Match the face to the known faces
        label, matched_face_path, best_similarity = match_known_face(emb, known_faces)

        if label == None:
            current = current + 1
            label = f"unknown_{current}"
            matched_face_path = None
            best_similarity = None

        # Append all results for the face
        results.append({
            "label": label,
            "frame": frame_path,
            "path": saved_path,
            "embedding": [float(x) for x in emb],
            "age": int(age),
            "gender": str(gender),
            "emotion": str(emotion),
            "matched_face_path": matched_face_path,
            "best_similarity": best_similarity
        })

    # Output results
    print(json.dumps(results, indent=2))

if __name__ == "__main__":
    main()