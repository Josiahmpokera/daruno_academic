Please follow these rules
- Dont return the code in the response
- Dont return the error message in the response
- If there is an error return the error in the response
- If there is no error return the success in the response
- The success message should be in the format of "Success: {message}"
- The error message should be in the format of "Error: {message}"



## API level Rules
- The API should return a JSON response
- The JSON response should have a "success" key with a boolean value
- The JSON response should have an "error" key with a string value
- The JSON response should have a "data" key with an object value
- If the "success" key is true, the "data" key should contain the requested data
- If the "success" key is false, the "error" key should contain the error message
- Always use try and catch to handle errors
- If there is an exception, return the exception message in the "error" key
- If there is no exception, return the success message in the "success" key
- The "data" key should contain the requested data if the "success" key is true
- The "data" key should be empty if the "success" key is false
- The "error" key should be empty if the "success" key is true

