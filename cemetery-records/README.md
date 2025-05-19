# Cemetery Records WordPress Plugin

A WordPress plugin for managing cemetery records with image support. This plugin allows you to import, manage, and display cemetery records with associated images and metadata.

## Version
1.3.5

## Features
- Import cemetery records from JSON files
- Support for extracted images and source page images
- Custom post type for cemetery records
- Image handling and attachment management
- Import/Export functionality
- Administrator capabilities management

## Requirements
- WordPress 5.2 or higher
- PHP 7.2 or higher
- Write permissions for the WordPress uploads directory

## Installation
1. Download the plugin zip file
2. Go to WordPress admin panel > Plugins > Add New
3. Click "Upload Plugin" and choose the downloaded zip file
4. Click "Install Now"
5. After installation, click "Activate"

## Usage

### Managing Records
1. Navigate to Cemetery Records in the WordPress admin menu
2. Click "Add New" to create a record manually
3. Fill in the record details and add images
4. Click "Publish" to save the record

### Importing Records
1. Go to Cemetery Records > Import/Export
2. Choose your JSON file containing the records
3. Specify the paths to your extracted images and source pages
4. Click "Import Records" to start the import process

### Exporting Records
1. Go to Cemetery Records > Import/Export
2. Click "Export Records" to download a JSON file of all records

### Image Handling
- Extracted images are automatically set as featured images
- Source page images are linked to the record
- Images are processed and optimized during import
- Multiple image sizes are generated for different uses

## File Structure
```
cemetery-records/
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   ├── public.css
│   │   └── cemetery-records.css
│   ├── js/
│   │   ├── admin.js
│   │   └── import-export.js
│   └── images/
├── includes/
│   ├── class-cemetery-records.php
│   └── class-cemetery-records-import-export.php
├── languages/
├── templates/
├── cemetery-records.php
└── README.md
```

## Support
For support or bug reports, please contact the Phillipston Historical Society.

## License
This plugin is licensed under the GPL v2 or later.

## Credits
Developed for the Phillipston Historical Society to manage and preserve cemetery records. 