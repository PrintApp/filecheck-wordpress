import os
import zipfile
import shutil

def package_plugin():
    plugin_name = "filecheck"
    zip_name = "filecheck.zip"
    
    # Files/directories to include
    targets = [
        "assets",
        "includes",
        "filecheck.php",
        "readme.txt",
        "license.txt"
    ]
    
    # Remove existing zip and temp dir if they exist
    if os.path.exists(zip_name):
        try:
            os.remove(zip_name)
            print(f"Removed existing {zip_name}")
        except Exception as e:
            print(f"Could not remove existing {zip_name}: {e}")
            
    if os.path.exists(plugin_name):
        try:
            shutil.rmtree(plugin_name)
        except Exception as e:
            print(f"Could not clean up existing {plugin_name} directory: {e}")
        
    print(f"Creating temporary directory: {plugin_name}...")
    os.makedirs(plugin_name, exist_ok=True)
    
    for target in targets:
        if not os.path.exists(target):
            print(f"Warning: {target} does not exist. Skipping.")
            continue
            
        dest = os.path.join(plugin_name, target)
        if os.path.isdir(target):
            shutil.copytree(target, dest)
            print(f"Copied directory: {target} -> {dest}")
        else:
            shutil.copy2(target, dest)
            print(f"Copied file: {target} -> {dest}")
            
    print(f"Zipping {plugin_name} into {zip_name}...")
    with zipfile.ZipFile(zip_name, 'w', zipfile.ZIP_DEFLATED) as zipf:
        for root, dirs, files in os.walk(plugin_name):
            for file in files:
                filepath = os.path.join(root, file)
                # Store with the correct relative path structure inside the zip
                arcname = os.path.relpath(filepath, start=os.getcwd())
                zipf.write(filepath, arcname)
                
    # Clean up temp folder
    try:
        shutil.rmtree(plugin_name)
        print(f"Cleaned up temporary directory: {plugin_name}")
    except Exception as e:
        print(f"Warning: Could not remove temporary directory {plugin_name}: {e}")
        
    print(f"\nSuccess! Built package: {os.path.abspath(zip_name)}")

if __name__ == "__main__":
    package_plugin()
