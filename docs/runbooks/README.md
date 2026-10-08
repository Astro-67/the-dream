# Runbooks

Placeholders. Each runbook is written before the milestone that first needs it.

| Runbook                         | Covers                                                                                                                                                   | Status   |
| ------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- |
| Deploy                          | Forge zero-downtime deploy, migrations, `queue:restart`, rollback                                                                                        | To write |
| Restore + outbox reconciliation | RDS point-in-time restore, then reconciling pending outbox rows against each integration before workers restart ([architecture §12](../architecture.md)) | To write |
| Key rotation                    | `APP_KEY` rotation with `APP_PREVIOUS_KEYS`, the ZRA supplier key, VFMS and channel-manager credentials                                                  | To write |
