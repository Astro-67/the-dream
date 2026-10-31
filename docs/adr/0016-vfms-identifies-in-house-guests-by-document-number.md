# VFMS identifies in-house guests by document number, so one document is in-house once per property

After check-in, VFMS knows a guest only by document number: the change-room, extra-services and check-out calls carry `documentNumber` and no stay, room or reservation reference (VFMS guide §2.2, §2.8, §2.11). If the same document were in-house twice at one property, a service or check-out could land on the wrong stay. At Zanzibar properties Karafuu therefore refuses check-in while the same document number is already in-house at that property, naming the other room.

Related choices that follow from the same fact:

- Extra services are sent under the document the accommodation request was opened with. Check-out lists every guest still in-house in the room.
- A guest who leaves before their room gets a check-out call for their document only (guest departure), so VFMS stops the infrastructure levy for them.
- Every call keeps one reference number across retries. Because the guide does not say how VFMS treats a repeated reference, an unknown outcome is checked against the accommodation report before resending.

## Considered options

- **Allow duplicates and pick the most recent stay.** Rejected: VFMS, not Karafuu, decides which accommodation a call hits, and a wrong guess misfiles tax.
- **Register the second room under another guest's document.** Left to staff: Karafuu does not invent a document.

## Consequences

- A parent booking two family rooms registers a different guest as the first guest of the second room.
- Two guests from different countries with the same document number cannot be in-house together. Accepted as rare; VFMS offers no other key.
- Document numbers stay encrypted. A hidden SHA-256 HMAC of the whitespace-stripped, uppercased number supports comparison; its key is the decoded `APP_KEY`. Rehash all existing documents under the new key before reopening check-in and guest additions after key rotation; `APP_PREVIOUS_KEYS` decrypts old values but does not update search hashes.
