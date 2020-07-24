# TableDog

TableDog is a utility that simplifies the synchronized modification of
[UniNAT's](https://github.com/vpn-util/uninat) table files.

## The problem

If you want to use *UniNAT* to map IPv4 subnets, you may be interested in doing
this dynamically. For this reason, *UniNAT* provides a mechanism with which you
can modify and reload the subnet-mapping without restarting the process:

1. Acquiring the exclusive lock for the table file via Linux'
   [flock(2)](https://linux.die.net/man/2/flock) syscall. (This is required for
   process synchronization.)

2. Altering the locked table file.
3. Unlocking the file again.
4. Triggering the UniNAT instances to reload their configuration from the file
   via `SIGUSR1`.

Now, the problem is that most of these operations are either POSIX- or
Linux-dependent, which requires using techniques like
[P/Invoke](https://docs.microsoft.com/en-us/dotnet/standard/native-interop/pinvoke)
or [JNI](https://en.wikipedia.org/wiki/Java_Native_Interface), if we want to
perform a configuration update via a .NET or Java application.

## The solution

For that reason, the *TableDog* server exists: It provides a standard,
ASCII-based, TCP/IPv4 frontend, which can be used modifying the different table
files and triggering the *UniNAT*-refresh.

The TCP-interface has been designed as
[REPL](https://en.wikipedia.org/wiki/Read%E2%80%93eval%E2%80%93print_loop)
that can be used with any basic implementation of a telnet-like application.

## Interface

### Commands

All supported commands are case-insensitive and need to be terminated by a
`\r\n` (ASCII: `0x0D`, `0x0A`) combination. Both rules also apply for all
responses.

#### Querying table entries

```
QUERY {PREROUTING/POSTROUTING} <ORIGINAL-ADDRESS>
```

This command instructs *TableDog* to respond with all table entries, whose
*original-address*-component match the specified IPv4-range.

The **first parameter** specifies which of both tables (`PREROUTING` or
`POSTROUTING`) will be used for looking for matching table entries. The
**second parameter** is expected to be a valid IPv4-address (range) in
CIDR-notation.

If everything went well, the following response will be sent to the requester:

```
QUERY-OK
COUNT *number of matching entries*
ENTRY *original address* *replacement address*
```

If an error occurred, the first line will be `QUERY-FAILED` and the normal
error behavior will take place.

**Note:** If no matching entry was found, the result will still start with
`QUERY-OK` and the `COUNT`-line, but no further line will be sent.

#### Setting a table entry

```
SET {PREROUTING/POSTROUTING} <ORIGINAL-ADDRESS> <REPLACEMENT-ADDRESS>
```

Successful response:

```
SET-OK
```

Error response:

```
SET-FAILED
```

#### Deleting a table entry

```
DELETE {PREROUTING/POSTROUTING} <ORIGINAL-ADDRESS>
```

Successful response:

```
DELETE-OK
```

Error response:

```
DELETE-FAILED
```

#### Failures

```
{QUERY/SET/DELETE}-FAILED
E{IO/REQ/UKN}
*A human-readable error message*
```

TODO: Improve command documentation; describe the configuration file :D
