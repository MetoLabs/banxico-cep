# Test data

All tests use mocked HTTP responses and synthetic payment values. They do not
contact Banxico. Account numbers with zero-filled suffixes are placeholders,
not customer accounts. Institution codes and Banxico field labels describe the
public protocol.

Never copy customer exports or live payment responses into fixtures. Keep local
inputs in the ignored `private/` directory. The `examples/` directory is also
ignored and must not be force-added to Git.
