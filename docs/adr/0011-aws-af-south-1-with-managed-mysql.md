# AWS af-south-1, with managed MySQL and point-in-time recovery

The PRD targets at most 15 minutes of data loss and restoration within 4 hours, and Thedream stores guest identity documents for East African hotels. Production runs in AWS `af-south-1` (Cape Town): the app on EC2 provisioned through Forge, and the database on RDS MySQL 8 with point-in-time recovery, in the same region. The database lives apart from the app server, so losing the app server never loses data. The architecture itself stays region-agnostic: nothing in the code depends on AWS or on this region.

## Consequences

- `af-south-1` is an opt-in region and must be enabled on the AWS account first.
- Whether storing Tanzanian guest data in South Africa is allowed under Tanzania's Personal Data Protection Act 2022 is unresolved. Resolving it is a go-live gate before the first real guest record, not just a risk-register item.
