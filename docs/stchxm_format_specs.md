# Stochastix Time-Series Metric Data File Format Specification (Version 1)

## 1. Overview and Purpose

This document specifies Version 1 of a binary file format, identified by the `.stchxm` extension, designed for storing time-aligned performance metrics calculated at the end of a backtest run. Its primary purpose is to provide a compact, high-performance storage solution for time-series data required for plotting (e.g., Equity Curve, Alpha, Drawdown), keeping it separate from the main backtest summary results.

All multi-byte numerical values in this format are stored in **Big-Endian** (network byte order).

## 2. File Structure

The binary file is composed of four main sections:

1.  **Header Section:** A fixed-size block at the beginning of the file containing metadata about the file's contents.
2.  **Timestamp Block:** A single, contiguous block of all timestamps for which metric values were calculated. This serves as the master time index for all series in the file.
3.  **Series Directory:** A contiguous block of fixed-size records, where each record defines a single metric series (e.g., "equity:value" or "alpha:value") contained in the file.
4.  **Data Blocks:** A final section containing the actual metric values, with one contiguous block of data per series defined in the directory.

```
+---------------------------+
|      Header Section       | (64 bytes)
+---------------------------+
|     Timestamp Block       | (Timestamp Count * 8 bytes)
+---------------------------+
|      Series Directory     | (Series Count * 64 bytes)
+---------------------------+
| Data Block for Series 1   | (Timestamp Count * 8 bytes)
+---------------------------+
| Data Block for Series 2   | (Timestamp Count * 8 bytes)
+---------------------------+
| ...                       |
+---------------------------+
| Data Block for Series N   | (Timestamp Count * 8 bytes)
+---------------------------+
```

## 3. Header Section Definition (Version 1)

The header is a fixed-size block of 64 bytes.

| Offset (Bytes) | Length (Bytes) | Field Name        | Data Type    | PHP `pack` Ref. | Description / Value for Version 1                             |
|:---------------|:---------------|:------------------|:-------------|:----------------|:--------------------------------------------------------------|
| 0              | 8              | Magic Number      | ASCII String | `a8`            | "STCHXM01" (Identifies file as STCHX Metric v1)               |
| 8              | 2              | Format Version    | `uint16_t`   | `N`             | `1`                                                           |
| 10             | 1              | Value Format Code | `uint8_t`    | `C`             | `1` (Indicates: 8-byte IEEE 754 Double Precision, Big-Endian) |
| 11             | 1              | Padding Byte      | `uint8_t`    | `x`             | Null byte for alignment.                                      |
| 12             | 4              | Series Count      | `uint32_t`   | `N`             | The total number of unique metric series in the file.         |
| 16             | 8              | Timestamp Count   | `uint64_t`   | `J`             | The number of records/timestamps in each series.              |
| 24             | 40             | Reserved          | Bytes        | `x40`           | Set to null bytes (`\0`). Reserved for future use.            |

## 4. Timestamp Block Definition

This section starts immediately after the 64-byte header. It contains a single, unbroken sequence of `Timestamp Count` records. Each timestamp is an 8-byte `uint64_t` representing the Unix timestamp in seconds (UTC).

This block acts as the time-axis for all data series that follow.

## 5. Series Directory Definition

This section follows the Timestamp Block. It contains `Series Count` records, each 64 bytes long, defining the properties of each data series stored in the file.

**Structure of a Single Series Directory Entry (64 bytes):**

| Offset within Record | Length (Bytes) | Field Name | Data Type    | PHP `pack` Ref. | Description                                                           |
|:---------------------|:---------------|:-----------|:-------------|:----------------|:----------------------------------------------------------------------|
| 0                    | 32             | Metric Key | ASCII String | `a32`           | The primary key of the metric (e.g., "equity", "alpha"). Null-padded. |
| 32                   | 32             | Series Key | ASCII String | `a32`           | The specific series from the metric (e.g., "value"). Null-padded.     |

## 6. Data Blocks Definition

This section starts immediately after the Series Directory. It contains `Series Count` contiguous blocks of data. Each block contains `Timestamp Count` values, corresponding to one of the series defined in the directory. The order of the data blocks must match the order of the entries in the Series Directory.

For Version 1, each value is an 8-byte, Big-Endian `double`. A value of `NAN` (Not A Number) is used to represent null or non-existent data points for a given timestamp.
