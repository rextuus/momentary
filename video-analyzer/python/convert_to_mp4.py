import sys
import os
import subprocess
import json

def convert_to_mp4(input_path, output_path):
    print(f"Converting {input_path} to {output_path}...")
    
    try:
        # ffmpeg command for web-optimized MP4
        # Added -profile:v high -level 4.0 for better compatibility and ensured moov atom is at the beginning
        # Using -vf scale=-2:720 to normalize height and fix aspect ratio issues if needed
        command = [
            'ffmpeg', '-y', '-i', input_path,
            '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
            '-profile:v', 'high', '-level', '4.0',
            '-pix_fmt', 'yuv420p',
            '-vf', 'scale=-2:min(720\,ih)',
            '-c:a', 'aac', '-b:a', '128k',
            '-movflags', '+faststart',
            output_path
        ]
        
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        stdout, stderr = process.communicate()
        
        if process.returncode != 0:
            return {"success": False, "error": stderr}
        
        return {"success": True, "output_path": output_path}
        
    except Exception as e:
        return {"success": False, "error": str(e)}

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print(json.dumps({"success": False, "error": "Usage: python convert_to_mp4.py <input_path> <output_path>"}))
        sys.exit(1)
        
    input_file = sys.argv[1]
    output_file = sys.argv[2]
    
    result = convert_to_mp4(input_file, output_file)
    print(json.dumps(result))
