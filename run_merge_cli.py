import json
import csv
import argparse

def extract_key_names(header):
    """
    Extracts potential last names from the JSON header field for matching.
    This function is designed to handle the specific formats in your data.
    """
    header_lower = header.lower()
    
    # Handle specific patterns with multiple names first (e.g., "Harris & Putnam")
    if ' & ' in header:
        parts = header.split(' & ')
        return [p.split()[-1] for p in parts]
    if ',' in header:
        parts = header.split(',')
        return [p.strip().split()[-1] for p in parts]
    
    # Handle general cases by splitting and removing common non-name words
    names = header.replace('-', ' ').split()
    stop_words = {'family', 'bench', 'of', 'and', 'or', '2', '0', '1', '14', '21', 'pg', 'right', 'l.c.r.'}
    
    # Filter for words that are likely to be names
    potential_names = [word for word in names if word.isalpha() and len(word) > 1 and word.lower() not in stop_words]
    
    if potential_names:
        # In most cases, the last relevant word is the name
        return [potential_names[-1]]
        
    # Fallback for simple headers ("Adams") or headers with initials ("D. Batchelor")
    if len(names) > 0:
        last_word = names[-1]
        if last_word.isalpha() and last_word.lower() not in stop_words:
            return [last_word]
            
    return []

def merge_cemetery_data(json_file_path, csv_file_path, output_file_path):
    """
    Merges cemetery plot data from a CSV file into a JSON file.

    Args:
        json_file_path (str): The path to the input JSON file.
        csv_file_path (str): The path to the input CSV file from the spreadsheet.
        output_file_path (str): The path to save the merged JSON file.
    """
    try:
        with open(json_file_path, 'r', encoding='utf-8') as f:
            cemetery_data = json.load(f)
    except FileNotFoundError:
        print(f"Error: The input JSON file was not found at '{json_file_path}'")
        return
    except json.JSONDecodeError:
        print(f"Error: The file '{json_file_path}' is not a valid JSON file.")
        return

    spreadsheet_lookup = {}
    try:
        with open(csv_file_path, 'r', newline='', encoding='utf-8') as f:
            reader = csv.DictReader(f)
            # The header in your CSV file is 'LAST NAME ', with a trailing space.
            # We will check for both possibilities to make the script more robust.
            last_name_header = 'LAST NAME'
            if 'LAST NAME ' in reader.fieldnames:
                last_name_header = 'LAST NAME '

            for row in reader:
                if not any(row.values()): # Skip completely empty rows
                    continue
                
                last_name = row.get(last_name_header, '').strip()
                print(f"\nChecking. {last_name}.")

                if last_name:
                    # Use a lowercase version of the name as the key for consistent matching
                    spreadsheet_lookup[last_name.lower()] = row
    except FileNotFoundError:
        print(f"Error: The input CSV file was not found at '{csv_file_path}'")
        return

    match_count = 0
    # Iterate through each record in the JSON data and update it
    for record in cemetery_data:
        # Skip records that have been manually populated
        if record.get("Plot Data") == "Plot info goes here.":
            continue
        
        header = record.get('Header', '')
        key_names = extract_key_names(header)
        
        for name in key_names:
            # Check for a case-insensitive match in our lookup table
            if name.lower() in spreadsheet_lookup:
                match_count += 1
                spreadsheet_row = spreadsheet_lookup[name.lower()]
                
                # Update Plot Number by concatenating BLOCK and LOT #
                block = spreadsheet_row.get('BLOCK', '')
                lot_num = spreadsheet_row.get('LOT #', '')
                record['Plot Number'] = f"{block}{lot_num}".strip()
                
                # Update Plot Data with all info from the spreadsheet row
                plot_data_items = []
                for key, value in spreadsheet_row.items():
                    if value and value.strip(): # Only include columns with data
                        plot_data_items.append(f"{key.strip()}: {value.strip()}")
                record['Plot Data'] = " | ".join(plot_data_items)
                
                # Stop after the first successful match for this record
                break

    # Save the updated data to the output file
    with open(output_file_path, 'w', encoding='utf-8') as f:
        json.dump(cemetery_data, f, indent=2)
    
    print(f"\nMerge complete. {match_count} records were updated.")
    print(f"The final merged data has been saved to '{output_file_path}'.")


if __name__ == '__main__':
    # Set up the command-line argument parser
    parser = argparse.ArgumentParser(
        description="Merge cemetery plot data from a CSV spreadsheet into a JSON file.",
        formatter_class=argparse.RawTextHelpFormatter # For better help text formatting
    )
    
    parser.add_argument(
        '-j', '--json_input', 
        type=str, 
        required=True, 
        help="Path to the input JSON file."
    )
    parser.add_argument(
        '-c', '--csv_input', 
        type=str, 
        required=True, 
        help="Path to the input CSV file."
    )
    parser.add_argument(
        '-o', '--output', 
        type=str, 
        required=True, 
        help="Path for the output (merged) JSON file."
    )
    
    args = parser.parse_args()
    
    # Run the main function with the provided file paths
    merge_cemetery_data(args.json_input, args.csv_input, args.output)