#!/usr/bin/env python3
"""
PSD to Word Converter

This script converts PSD files to Word documents by first converting them to images,
then inserting those images into Word documents. It processes all PSD files in the 
specified input directory and saves the resulting Word documents in the specified output directory.

Requirements:
- Python 3.6+
- Pillow (PIL)
- python-docx

Usage:
    python psd_converter.py --input /path/to/psd/files --output /path/to/output/directory
"""

import os
import sys
import argparse
import logging
import tempfile
import subprocess
from pathlib import Path

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

def check_requirements():
    """Check if required libraries are installed and suggest installation if not."""
    required_packages = ['PIL', 'docx']
    
    missing_packages = []
    install_names = {'PIL': 'Pillow', 'docx': 'python-docx'}
    
    for package in required_packages:
        try:
            __import__(package)
        except ImportError:
            missing_packages.append(package)
    
    if missing_packages:
        logger.error("Missing required packages: %s", 
                    ", ".join(install_names.get(pkg, pkg) for pkg in missing_packages))
        logger.info("Install required packages using:")
        logger.info("pip install %s", 
                   " ".join(install_names.get(pkg, pkg) for pkg in missing_packages))
        sys.exit(1)

def check_external_tools():
    """Check if optional external tools are available."""
    # Check for ImageMagick (convert command)
    try:
        subprocess.run(
            ["convert", "-version"], 
            stdout=subprocess.PIPE, 
            stderr=subprocess.PIPE, 
            check=False
        )
        logger.info("ImageMagick found - will be used for better PSD conversion")
        return True
    except (subprocess.SubprocessError, FileNotFoundError):
        logger.warning("ImageMagick not found. Using PIL fallback for PSD conversion.")
        return False

def convert_psd_with_imagemagick(psd_path, output_path):
    """Convert PSD to PNG using ImageMagick."""
    try:
        logger.info(f"Converting with ImageMagick: {psd_path} -> {output_path}")
        subprocess.run(
            ["convert", psd_path, output_path],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=True
        )
        return os.path.exists(output_path)
    except subprocess.SubprocessError as e:
        logger.error(f"ImageMagick conversion failed: {e}")
        return False

def convert_psd_with_pil(psd_path, output_path):
    """Convert PSD to PNG using PIL."""
    try:
        from PIL import Image
        logger.info(f"Converting with PIL: {psd_path} -> {output_path}")
        
        # Open the PSD file
        img = Image.open(psd_path)
        
        # Convert to RGB if necessary (PSDs can have various color modes)
        if img.mode != 'RGB':
            img = img.convert('RGB')
            
        # Save as PNG
        img.save(output_path, format='PNG')
        
        return os.path.exists(output_path)
    except Exception as e:
        logger.error(f"PIL conversion failed: {e}")
        return False

def create_word_doc_with_image(image_path, docx_path):
    """Create a Word document with the image."""
    try:
        from docx import Document
        from docx.shared import Inches
        
        logger.info(f"Creating Word document: {docx_path}")
        
        # Create a new document
        document = Document()
        
        # Add the image - adjust size to fit page
        document.add_picture(image_path, width=Inches(6))
        
        # Save the document
        document.save(docx_path)
        
        return os.path.exists(docx_path)
    except Exception as e:
        logger.error(f"Error creating Word document: {e}")
        return False

def process_file(psd_file, output_dir, temp_dir, use_imagemagick):
    """Process a single PSD file."""
    file_name = os.path.basename(psd_file)
    name_without_ext = os.path.splitext(file_name)[0]
    
    # Define output paths
    image_path = os.path.join(temp_dir, f"{name_without_ext}.png")
    docx_path = os.path.join(output_dir, f"{name_without_ext}.docx")
    
    # Step 1: Convert PSD to image
    conversion_success = False
    if use_imagemagick:
        conversion_success = convert_psd_with_imagemagick(psd_file, image_path)
        if not conversion_success:
            logger.warning("ImageMagick conversion failed, falling back to PIL")
    
    if not conversion_success:
        conversion_success = convert_psd_with_pil(psd_file, image_path)
    
    if not conversion_success:
        logger.error(f"Failed to convert {file_name} to image")
        return False
    
    # Step 2: Create Word document with the image
    word_success = create_word_doc_with_image(image_path, docx_path)
    
    if word_success:
        logger.info(f"Successfully converted {file_name} to Word")
        return True
    else:
        logger.error(f"Failed to create Word document for {file_name}")
        return False

def main():
    """Main function to handle the conversion process."""
    parser = argparse.ArgumentParser(description='Convert PSD files to Word documents.')
    parser.add_argument('--input', '-i', required=True, help='Input directory containing PSD files')
    parser.add_argument('--output', '-o', required=True, help='Output directory for Word documents')
    parser.add_argument('--verbose', '-v', action='store_true', help='Enable verbose output')
    parser.add_argument('--no-imagemagick', action='store_true', help='Disable ImageMagick usage even if available')
    
    args = parser.parse_args()
    
    # Set logging level
    if args.verbose:
        logger.setLevel(logging.DEBUG)
    
    # Check if required packages are installed
    check_requirements()
    
    # Check for ImageMagick
    use_imagemagick = False if args.no_imagemagick else check_external_tools()
    
    # Validate directories
    input_dir = os.path.abspath(args.input)
    output_dir = os.path.abspath(args.output)
    
    if not os.path.isdir(input_dir):
        logger.error(f"Input directory does not exist: {input_dir}")
        sys.exit(1)
    
    # Create output directory if it doesn't exist
    os.makedirs(output_dir, exist_ok=True)
    
    # Create temporary directory for intermediate files
    with tempfile.TemporaryDirectory() as temp_dir:
        logger.info(f"Processing PSD files from {input_dir}")
        logger.info(f"Output will be saved to {output_dir}")
        
        # Find all PSD files
        psd_files = [os.path.join(input_dir, f) for f in os.listdir(input_dir) 
                    if f.lower().endswith('.psd')]
        
        if not psd_files:
            logger.warning(f"No PSD files found in {input_dir}")
            sys.exit(0)
        
        logger.info(f"Found {len(psd_files)} PSD files")
        
        # Process each file
        success_count = 0
        for psd_file in psd_files:
            if process_file(psd_file, output_dir, temp_dir, use_imagemagick):
                success_count += 1
        
        # Report results
        logger.info(f"Conversion complete: {success_count} of {len(psd_files)} files converted successfully")
        if success_count < len(psd_files):
            logger.warning(f"Failed to convert {len(psd_files) - success_count} files")

if __name__ == "__main__":
    main()
