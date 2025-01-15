# Reports With Record Sums
## Overview
Create reports with data tables that provide the ability to sum a field across instances in the record. Also provides comparisons between columns and simple math functions.

## Settings
These settings can be repeated together to define multiple reports.

1. Name of Report
   * Text value to identify the report. Will be shown at the top of the report table.
2. Repeatable Header and Column Values
   1. Plain Text to Display at Header of the Column
      * Text to display in the header for the defined column in the report. Only accepts plain text values, no data piping or smart variables allowed.
   2. Data Value for Column (accepts basic REDCap field piping)
      * Values to be used for the column in each row/record in the report table. The values here allow for REDCap data piping and smart variables. Basic math with order of operations can also be performed here: addition, subtraction, multiplication, and division. There are also a few special tags specific to this module, detailed below.
        * :col_#:
          * References the value from column '#', starting at an index of 1.
        * :instance_sum[<field_name>]:
          * Will sum the values for the chosen field_name across all instances and events for a record.
      * Examples of column value definitions:
        * :col_1: + [record_count]
          * Will take the value from column 1 and will add the value from the 'record_count' field.
        * (:col_1: - 3) + :col_1: * 4
          * Take the value from column 1 and subtract 3. Then, add the total of column 1 multiplied by 4 to that result. This should match basic order of operations in arithmetic.
        * :instance_sum[record_count]:
          * Column will add the values from all instances and events for the 'record_count' field.