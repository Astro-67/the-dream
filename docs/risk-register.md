# Risk register

| ID | Risk | Impact | Mitigation | Gate | Owner | Status |
|---|---|---|---|---|---|---|
| R-001 | **PDPA 2022 cross-border transfer.** Production runs in AWS `af-south-1` (South Africa). Thedream stores Tanzanian guests' names, nationalities and identity document numbers. Tanzania's Personal Data Protection Act 2022 regulates personal data, including transfers outside the country. | Hosting outside Tanzania may be unlawful without the required safeguards or approvals. | Legal review of the cross-border transfer rules for the chosen region, and of any registration or approval needed. | **Go-live gate:** resolved before the first real guest record is stored. | Product + legal adviser | Open |

Related: [ADR 0011](adr/0011-aws-af-south-1-with-managed-mysql.md), [PRD §16](prd/PRD.md).
