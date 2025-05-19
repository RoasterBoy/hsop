import json
import mysql.connector

# MySQL Connection Configuration - Define it here, outside the function
db_config = {
    'user': 'root',        # Replace with your MySQL username
    'password': 'My$01331L',  # Replace with your MySQL password
    'host': 'localhost',  # Or your MySQL server address
    'database': 'cemetery_data'
}

def update_or_insert_data(json_file_path):
    """
    Updates existing records in the database or inserts new ones based on JSON data.
    """
    connection = None
    try:
        connection = mysql.connector.connect(**db_config)
        cursor = connection.cursor()

        with open(json_file_path, 'r') as file:
            new_data = json.load(file)

        for record in new_data:
            source_page = record.get('source_page')  # Assuming source_page is the unique identifier

            # Check if the record exists in the database
            select_query = "SELECT * FROM cemetery_records WHERE source_page = %s"
            cursor.execute(select_query, (source_page,))
            existing_record = cursor.fetchone()

            if existing_record:
                # Update the existing record
                update_query = """
                    UPDATE cemetery_records SET 
                        extracted_image = %s, 
                        image_bounding_box = %s, 
                        page_header = %s, 
                        page_footer = %s, 
                        page_location = %s, 
                        page_additional_info = %s, 
                        image_caption = %s
                    WHERE source_page = %s
                """
                values = (
                    record.get('extracted_image'),
                    json.dumps(record.get('image_bounding_box')),
                    record.get('page_header'),
                    record.get('page_footer'),
                    record.get('page_location'),
                    record.get('page_additional_info'),
                    record.get('image_caption'),
                    source_page
                )
                cursor.execute(update_query, values)
                print(f"Updated record: {source_page}")
            else:
                # Insert the new record
                insert_query = """
                    INSERT INTO cemetery_records (source_page, extracted_image, image_bounding_box, 
                                         page_header, page_footer, page_location, 
                                         page_additional_info, image_caption)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                """
                values = (
                    record.get('source_page'),
                    record.get('extracted_image'),
                    json.dumps(record.get('image_bounding_box')),
                    record.get('page_header'),
                    record.get('page_footer'),
                    record.get('page_location'),
                    record.get('page_additional_info'),
                    record.get('image_caption')
                )
                cursor.execute(insert_query, values)
                print(f"Inserted record: {source_page}")

        connection.commit()
        print("Database update/insert complete.")

        # Optional:  Identify records to delete (records in DB but not in JSON)
        # print("Identifying records for potential deletion...")
        # source_pages_in_json = [rec['source_page'] for rec in new_data]
        # delete_query = "SELECT source_page FROM cemetery_records WHERE source_page NOT IN %s"
        # cursor.execute(delete_query, (source_pages_in_json,))
        # records_to_delete = cursor.fetchall()
        # print(f"Records to potentially delete: {records_to_delete}")
        # You would then add logic to delete these if desired.

    except mysql.connector.Error as err:
        print(f"MySQL Error: {err}")
        if connection:
            connection.rollback()
    except json.JSONDecodeError as e:
        print(f"JSON Decode Error: {e}")
        if connection:
            connection.rollback()
    except FileNotFoundError:
        print(f"Error: File not found at {json_file_path}")
    except Exception as e:
        print(f"An unexpected error occurred: {e}")
        if connection:
            connection.rollback()

    finally:
        if connection:
            cursor = connection.cursor()
            cursor.close()
            connection.close()
            print("MySQL connection closed.")

if __name__ == "__main__":
    json_file = 'metadata.json'  #  Path to your updated JSON file
    update_or_insert_data(json_file)
