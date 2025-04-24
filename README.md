# hsop
Historical Society of Phillipston

This material presents various items drawn from research by members of the society and some data-munging with the help of ChatGPT and Claude. 

Stay tuned. This is going to be fun.

[Lamb Family Tree](./lamb_family_tree_combined_v2.html)

Use the following command to  generate a page for a cemetery:

```
python ~/wiseai/convert_json_to_html.py imgsrc/metadata.json cemetery.html header.html
```
Where:

- _imgsrc_ is the name of the directory where the metadata and marker images are stored
- _cemetery.html_ is the name of the output HTML file
- _header.html_ is the name of the file that appears at the top of the _cemetery.html_ file. It contains an image as well as H1 and H2 items.
