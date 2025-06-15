# Stochastix Binary OHLCV Data File Format Specification (Version 1)

## 1. Overview and Purpose

This document specifies Version 1 of a binary file format ("STCHXBF1") designed for storing OHLCV (Open, High, Low, Close, Volume) time-series data. The primary use case for this format is within a custom financial backtesting framework, emphasizing efficient storage and fast data retrieval, especially for time-based ranges.

All multi-byte numerical values in this format are stored in **Big-Endian** (network byte order).

## 2. File Structure

The binary file is composed of two main sections:

1.  **Header Section:** A 64-byte block at the beginning of the file containing metadata about the data series.
2.  **Data Records Section:** A sequence of fixed-size (48-byte) data records, each representing one OHLCV data point.

```
+------------------------+
|     Header Section     | (64 bytes)
+------------------------+
| Data Record 1          | (48 bytes)
+------------------------+
| Data Record 2          | (48 bytes)
+------------------------+
| ...                    |
+------------------------+
| Data Record N          | (48 bytes)
+------------------------+
```

## 3. Header Section Definition (Version 1)

The header is a fixed-size block of 64 bytes.

| Offset (Bytes) | Length (Bytes) | Field Name             | Data Type    | PHP `pack` Ref. | Description / Value for Version 1                                            |
|:---------------|:---------------|:-----------------------|:-------------|:----------------|:-----------------------------------------------------------------------------|
| 0              | 8              | Magic Number           | ASCII String | `a8`            | "STCHXBF1" (Identifies file as STCHX Binary Format v1)                       |
| 8              | 2              | Format Version         | `uint16_t`   | `N`             | `1`                                                                          |
| 10             | 2              | Header Length          | `uint16_t`   | `N`             | `64` (Total size of this header in bytes for v1)                             |
| 12             | 2              | Record Length          | `uint16_t`   | `N`             | `48` (Size of one OHLCV data record in bytes for v1)                         |
| 14             | 1              | Timestamp Format Code  | `uint8_t`    | `C`             | `1` (Indicates: 8-byte Unix Timestamp in seconds, Big-Endian `uint64_t`)     |
| 15             | 1              | OHLCV Data Format Code | `uint8_t`    | `C`             | `1` (Indicates: 8-byte IEEE 754 Double Precision, Big-Endian, for O,H,L,C,V) |
| 16             | 8              | Number of Data Records | `uint64_t`   | `J`             | Total count of OHLCV records in the file                                     |
| 24             | 16             | Symbol / Instrument    | ASCII String | `a16`           | e.g., "EURUSDT\0\0\0\0\0\0\0" (Null-padded if shorter than 16 bytes)         |
| 40             | 4              | Timeframe              | ASCII String | `a4`            | e.g., "M1\0\0" (Null-padded if shorter than 4 bytes)                         |
| 44             | 20             | Reserved               | Bytes        | `x20`           | Set to null bytes (`\0`). Reserved for future use.                           |

*(PHP `pack` specifiers are for reference in a PHP context: `a`=null-padded string, `N`=uint16_t BE, `C`=uint8_t, `J`=uint64_t BE, `x`=null byte. The file format itself is language-agnostic.)*

## 4. Data Records Section Definition (Version 1)

This section starts immediately after the 64-byte header. It contains a sequence of `Number of Data Records` entries (as specified in the header). Each record is 48 bytes long for Version 1.

**Crucial Assumption:** Data records **MUST** be sorted chronologically by the `Timestamp` field in ascending order.

**Structure of a Single Data Record (48 bytes):**

| Offset within Record (Bytes) | Length (Bytes) | Field Name  | Data Type  | PHP `pack` Ref. | Description                                     |
|:-----------------------------|:---------------|:------------|:-----------|:----------------|:------------------------------------------------|
| 0                            | 8              | Timestamp   | `uint64_t` | `J`             | Unix timestamp in seconds (UTC) since epoch.    |
| 8                            | 8              | Open Price  | `double`   | `E`             | Opening price.                                  |
| 16                           | 8              | High Price  | `double`   | `E`             | Highest price during the period.                |
| 24                           | 8              | Low Price   | `double`   | `E`             | Lowest price during the period.                 |
| 32                           | 8              | Close Price | `double`   | `E`             | Closing price.                                  |
| 40                           | 8              | Volume      | `double`   | `E`             | Traded volume (using `double` for flexibility). |

*(PHP `pack` specifiers are for reference in a PHP context: `J`=uint64_t BE, `E`=double BE. The file format itself is language-agnostic.)*

## 5. Required Features for Reader/Writer Implementation

The implementation of the reader/writer components should provide the following functionalities:

**A. Writing STCHXBF1 Files:**
1.  Ability to create new `.stchx` (or similar extension) files.
2.  Functionality to write the header section with provided metadata (e.g., symbol, timeframe) and automatically calculated/defaulted fields for Version 1 (magic number, version, lengths, format codes, number of records).
3.  Methods to append OHLCV data records individually or in batches. Data records must adhere to the 48-byte structure and be written in chronological order by timestamp.
4.  Ensure correct conversion of native data types to the specified Big-Endian binary formats (e.g., native floating-point numbers to 8-byte Big-Endian doubles, native integers to 8-byte Big-Endian `uint64_t` for timestamps).

**B. Reading STCHXBF1 Files:**
1.  Ability to open existing `.stchx` files for reading.
2.  Functionality to read and parse the 64-byte header.
3.  **Header Validation:**
    * Verify the Magic Number matches "STCHXBF1".
    * Confirm Format Version is `1` (or a version the reader supports).
    * Verify Header Length (`64`) and Record Length (`48`) match expected values for Version 1.
4.  Provide easy access to all metadata stored in the header (Symbol, Timeframe, Number of Data Records, etc.).
5.  **Efficiently Read Records by Timestamp Range:**
    * Implement a method to retrieve all OHLCV records where `T_start <= Record.Timestamp <= T_end`.
    * This method **must not** require loading the entire file into memory.
    * It should leverage the fixed record size and the sorted nature of timestamps by performing a binary search on the file to efficiently locate the starting record index corresponding to `T_start`.
    * After finding the start, it should read records sequentially until the record's timestamp exceeds `T_end` or the end of file is reached.
6.  **Read All Records:** Provide a mechanism to iterate through all data records sequentially from the beginning to the end of the file (respecting `Number of Data Records`).
7.  **Read Record by Index:** Implement a method to directly read the Nth data record (0-indexed) by calculating its byte offset (`Header Length + (N * Record Length)`) and using file seek operations.
8.  Ensure correct conversion of Big-Endian binary data from the file into native data types (e.g., 8-byte Big-Endian double to a native float/double).

**C. General Considerations:**
1.  Implement robust error handling for file operations (e.g., file not found, read/write errors, seeking errors, premature end-of-file).
2.  Handle potential format inconsistencies gracefully (e.g., if a file's header claims to be v1 but has an unexpected record length field).

## 6. Example Usage Context for the Backtesting Framework

* The framework will store historical market data (e.g., 1-minute EURUSD, daily AAPL) in this format. Files can contain millions of records.
* During backtesting, the framework will need to query specific historical date/time ranges (e.g., "fetch all 1-hour BTCUSD data from 2022-01-01 00:00:00 to 2022-01-31 23:59:59").
* Data visualization components might also use the reader to fetch data for specific periods to plot charts.

## 7. Future Considerations

* The `Format Version` field is designed to allow for future evolution of the file format. Parsers should ideally check this version.
* The `Reserved` space in the header provides a buffer for adding minor metadata fields in future revisions of Version 1 or for new format versions, without altering the offset of existing v1 fields if managed carefully.
