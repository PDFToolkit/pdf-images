# Security policy

English | [Français](SECURITY.fr.md)

Report a vulnerability privately through [GitHub Security Advisories](https://github.com/PDFToolkit/pdf-images/security/advisories/new).
Include the affected commit, a minimal input, configured limits and observed result.

The converters accept bytes supplied by the application and never open a path or URL.
Their per-instance limits bound input dimensions and parsing or decompression work.
Invalid and unsupported inputs are rejected explicitly; the documented tests verify these controls.
Configure limits for the workload and apply memory and execution-time quotas at the application boundary.
Passing this suite does not establish the absence of every possible vulnerability.

## Supported versions

The `0.1.x` series receives security fixes. PHP 8.3 or newer is required;
CI checks PHP 8.3, 8.4 and 8.5. Use a maintained PHP version with its security
updates.

The shared PDF model has its own [security policy](https://github.com/PDFToolkit/pdf/blob/master/SECURITY.md).
