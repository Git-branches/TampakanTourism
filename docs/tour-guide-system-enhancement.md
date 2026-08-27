# Tour Guide System Enhancement — Development Specification

**Project:** TampakanTourism
**Stack:** PHP / MySQL
**Type:** Enhancement to an existing system — **not** a rewrite

---

## Role

Act as a **Senior Full-Stack Developer and System Architect**.

Enhance the existing tourism management system by implementing the features below while **preserving the existing system workflow, UI design, database conventions, and functionality**.

Do not unnecessarily redesign existing pages or replace working functionality. First inspect the existing implementation, then integrate the requested features cleanly into the current architecture.

Reuse existing components, styles, authentication, notification/SMS mechanisms, and database conventions wherever possible.

---

## 1. Multiple Destination Selection in `tour-guide.php`

### Current implementation

The existing [tour-guide.php](../tour-guide.php) uses a standard single-selection destination dropdown:

```text
Destination

┌────────────────────────────────────────────┐
│ I am not sure yet – please advise        ▼ │
└────────────────────────────────────────────┘

Options:
- I am not sure yet – please advise
- Jaddas Falls
- Kolon Ridge
- Other existing destinations
```

### Required enhancement

Allow visitors to select **multiple destinations within one tour guide request**.

**Do NOT replace the existing dropdown with a native HTML multi-select.** Keep the current dropdown appearance and implement a **repeatable destination selection component**.

#### Initial state

The visitor initially sees exactly one destination dropdown:

```text
Destination

┌────────────────────────────────────────────┐
│ I am not sure yet – please advise        ▼ │
└────────────────────────────────────────────┘

+ Add another destination
```

#### After clicking `+ Add another destination`

Dynamically create another destination dropdown:

```text
Destination 1

┌────────────────────────────────────────────┐
│ Jaddas Falls                             ▼ │
└────────────────────────────────────────────┘

Destination 2

┌────────────────────────────────────────────┐
│ Kolon Ridge                              ▼ │
└────────────────────────────────────────────┘

+ Add another destination
```

Continue allowing additional destinations as needed.

### Destination component requirements

Each destination selector must:

- Use the existing destination data/source.
- Preserve the current dropdown visual style.
- Dynamically add another destination selector.
- Allow the visitor to remove an added destination.
- Prevent duplicate destination selections.
- Automatically exclude or disable destinations already selected in other dropdowns.
- Maintain proper form validation.
- Work correctly on desktop and mobile.
- Preserve the existing UI/UX style.

**Allowed:**

```text
Destination 1 → Jaddas Falls
Destination 2 → Kolon Ridge
```

**Must be prevented:**

```text
Destination 1 → Jaddas Falls
Destination 2 → Jaddas Falls
```

---

## 2. Special Handling for "I am not sure yet – please advise"

The existing option **I am not sure yet – please advise** must remain available, but is treated as a **special selection**.

This combination is logically conflicting and must not be allowed:

```text
Destination 1 → Jaddas Falls
Destination 2 → I am not sure yet – please advise
```

### Required behavior

If the visitor selects **I am not sure yet – please advise**:

- Do not allow additional specific destinations to be selected.
- Disable or hide the `+ Add another destination` button.
- Clearly indicate that the visitor is requesting assistance in choosing destinations.

If the visitor already selected a specific destination and then selects **I am not sure yet – please advise**, either:

- Clear the previously selected destinations after confirmation, **or**
- Prevent the selection and display a clear validation message.

Recommended message:

> "Please choose either specific destinations or 'I am not sure yet – please advise', but not both."

---

## 3. Store Multiple Destinations Correctly

Do **not** store multiple destination names as a comma-separated string in a single database field. Use a relational structure.

```text
tour_guide_requests
        |
        | 1
        |
        |----< tour_request_destinations >---- destinations
```

Example:

```text
Request Reference:
TG-273GR

Destinations:
├── Jaddas Falls
├── Kolon Ridge
└── Another Destination
```

All destinations must belong to the **same tour guide request** and the **same reference number**. Do not create separate tour guide requests for every destination.

---

## 4. Preserve the Existing Visitor Workflow

Do not replace the existing tour guide request process. The current workflow remains:

```text
Request Tour Guide
↓
Select Destination(s)
↓
Select Date / Time / Number of Visitors
↓
Input Your Name / Mobile Number / Email
(Optional)
↓
Anything the guide should know?
(Optional)
↓
Submit
↓
Generate Digital Receipt
↓
Tourism Officer
↓
View Tour Guide Requests
```

The only visitor-side change is that `Select Destination` becomes `Select Destination(s)`, using the repeatable dropdown implementation above.

---

## 5. Date / Time / Number of Visitors

Preserve the existing fields and validation. The visitor still selects:

- Date
- Time
- Number of Visitors

These values must remain associated with the same tour guide request containing all selected destinations.

---

## 6. Visitor Information

Preserve the existing optional fields:

- Name
- Mobile Number
- Email

These remain optional unless the existing system already requires them. Do not unnecessarily change their current behavior.

---

## 7. Additional Notes

Preserve **"Anything the guide should know?"** — optional.

Example content: elderly visitors, children, accessibility concerns, special requirements, etc.

Store this information with the original tour guide request.

---

## 8. Digital Receipt

After submission, continue generating the existing **Digital Receipt**. Do not redesign it unnecessarily — modify only where necessary to support multiple destinations.

The receipt should display:

- Reference Number
- All selected destination(s)
- Date
- Time
- Number of Visitors
- Visitor information, if provided
- Additional notes, if applicable
- Current request status

Example:

```text
Reference:
TG-273GR

Destinations:
1. Jaddas Falls
2. Kolon Ridge

Date:
August 30, 2026

Time:
9:00 AM

Visitors:
4
```

All destinations must appear under the same reference number.

---

## 9. Tourism Officer — Tour Guide Requests

Preserve the existing Tourism Officer request management. When an officer opens a request, display:

- Reference Number
- All selected destinations
- Requested date
- Requested time
- Number of visitors
- Visitor name, if provided
- Mobile number, if provided
- Email, if provided
- Additional notes, if provided
- Request status
- Assigned tour guide, if already assigned

Example:

```text
Reference:
TG-273GR

Destinations:
• Jaddas Falls
• Kolon Ridge

Date:
August 30, 2026

Time:
9:00 AM

Visitors:
4

Status:
Guide Assignment Pending
```

---

## 10. First Visitor Message — Request Received

Keep the existing two-stage notification process. **Do not combine the messages.**

After the Tourism Officer receives/reviews the request, send the first message:

> We received your tour guide request and are arranging a guide. We will contact you shortly.
>
> Ref: TG-273GR

The reference number must be dynamically generated. At this point:

- Do not include a tour guide name.
- Do not include a tour guide phone number.
- The guide has not yet been assigned.

---

## 11. Tour Guide Management — Admin Sidebar

Add a new **Tour Guides** section to the Admin sidebar:

```text
Dashboard
Tour Guide Requests
Tour Guides
Destinations
Reports
...
```

The Tour Guides module should allow the Admin to:

- View all tour guides
- Add tour guide
- Edit tour guide
- View tour guide details
- Upload profile picture
- Manage credentials
- Upload certificates
- Set ID validity
- Set tour guide status
- Generate Tour Guide ID
- Preview Tour Guide ID
- Print/download generated ID where supported
- Regenerate/update ID when information changes

---

## 12. Tour Guide Information

### Basic Information

- Full Name
- Address
- Mobile Number
- Email, if applicable
- Profile Picture

### Credentials

Allow multiple credentials, stored as structured records associated with the tour guide. Examples:

- Tour Guide Accreditation
- First Aid Training
- Tourism Training
- Other approved qualifications

### Certificates

Allow multiple certificate uploads with metadata:

- Certificate Name
- Issuing Organization
- Date Issued
- Expiration Date, if applicable
- Certificate File

Certificates must belong to the corresponding tour guide. Do not store certificates as unrelated uploads.

---

## 13. Tour Guide ID Generation

After the Admin saves a tour guide profile, the system generates a **Tour Guide ID** automatically from the information stored in the database. The Admin should not manually design an ID for every tour guide.

Use one consistent professional template containing:

- Front side
- Back side
- Unique Tour Guide ID Number
- Valid Until date
- One QR code on the front

---

## 14. Tour Guide ID — FRONT

Keep the front clean and focused on identification.

```text
MUNICIPAL TOURISM OFFICE

[LOGO]

[TOUR GUIDE PHOTO]

JUAN DELA CRUZ

TOUR GUIDE

Tour Guide ID:
TGID-2026-0001

Valid Until:
December 31, 2026

[QR CODE]
```

All data must be dynamically populated from the database.

---

## 15. Tour Guide ID — BACK

There must be **NO QR code on the back**. The QR code exists only on the front.

```text
MUNICIPAL TOURISM OFFICE

TOUR GUIDE IDENTIFICATION

This card certifies that the bearer is an authorized
tour guide under the Municipal Tourism Office.

────────────────────────────────

TOUR GUIDE INFORMATION

Address:
[Guide Address]

Contact:
[Guide Contact Number]

────────────────────────────────

QUALIFICATIONS / CREDENTIALS

• [Credential 1]
• [Credential 2]
• [Credential 3]

────────────────────────────────

IMPORTANT

This ID is valid only until the indicated expiration
date and is non-transferable.

If found, please return to:
Municipal Tourism Office
```

Do not overload the physical ID with full certificate documents, excessive personal information, long descriptions, or a second QR code.

---

## 16. QR Code — Tour Guide Verification

The QR code on the front must **not contain all tour guide information directly**. It should point to a secure verification endpoint/page.

```text
https://yourdomain.com/tour-guide/verify/TGID-2026-0001
```

Use a secure unique identifier/token appropriate to the existing system. The QR code opens the official Tour Guide Verification page.

---

## 17. Tour Guide QR Verification Page

The verification page is where the **detailed information** is displayed:

```text
TOUR GUIDE VERIFICATION

[PHOTO]

Juan Dela Cruz

Tour Guide ID:
TGID-2026-0001

Status:
✓ VALID / ACTIVE

Valid Until:
December 31, 2026

Address:
[Address]

Contact:
[Contact Number]

Credentials:
• Credential 1
• Credential 2
• Credential 3

Certificates:
• Certificate 1
• Certificate 2
• Certificate 3

Issued By:
Municipal Tourism Office
```

The verification page must always use the **latest database information**.

If the Admin changes the guide's status `Active → Revoked`, the page must immediately show `✕ REVOKED`. Likewise `Active → Expired` must show `✕ EXPIRED`.

---

## 18. Tour Guide Status

Implement the following statuses:

- Active
- Expired
- Suspended
- Revoked

Only eligible guides may be assigned to tour guide requests.

---

## 19. Tour Guide Availability

When the Tourism Officer opens a tour guide request, the system shows available tour guides. Availability considers:

- Tour guide status
- ID validity
- Requested date
- Requested time
- Existing tour guide assignments
- Destination qualification, if applicable

A guide should be selectable only when:

```text
Active
+
Valid ID
+
Available on requested date/time
+
Qualified, when applicable
```

Expired, suspended, and revoked guides must not be assignable.

---

## 20. Assign Tour Guide

Inside the Tour Guide Request section, provide **Assign Tour Guide**. The officer selects an available tour guide and confirms the assignment.

Recommended action label: **Assign Tour Guide & Notify Visitor**

After confirmation:

```text
Tour Guide Request
↓
Tour Guide Assigned
↓
Save Assignment
↓
Generate Message #2
↓
Notify Visitor
```

The assigned tour guide must be associated with the existing request. Do not create another request.

---

## 21. Second Visitor Message — Guide Assigned

After assignment, automatically generate the second message:

> Your Tour Guide for Jaddas Falls and Kolon Ridge is Juan Dela Cruz (09XXXXXXXXX).
>
> Meet at Municipal Tourism Office, Tampakan Municipal Hall, Kamagong St., Brgy. Poblacion, Tampakan, South Cotabato.
>
> Please present your digital receipt.

The message must dynamically populate:

- All selected destination(s)
- Tour guide name
- Tour guide phone number
- Meeting location
- Digital receipt reminder

The officer should not need to manually type the complete message.

---

## 22. Database Design

Do not store multiple destinations as a comma-separated string. Use a relational structure:

```text
tour_guide_requests
        |
        | 1
        |
        |----< tour_request_destinations >---- destinations
```

Example:

```text
TG-273GR
├── Jaddas Falls
├── Kolon Ridge
└── Another Destination
```

The tour guide assignment is stored against the **tour guide request**, not duplicated for every destination.

---

## 23. Security Requirements

### File Upload Security

For profile pictures and certificates:

- Validate file extension.
- Validate MIME type.
- Validate file size.
- Generate safe unique filenames.
- Prevent executable file uploads.
- Store uploaded documents securely.
- Prevent unauthorized access to private certificate files.

### QR Security

- Do not expose sensitive database IDs unnecessarily.
- Use secure unique verification identifiers/tokens.
- The verification endpoint must validate the identifier before displaying information.

### Authorization

Only authorized **Admin** users may:

- Add/edit tour guides
- Upload certificates
- Change credentials
- Change validity
- Change guide status
- Generate/regenerate IDs

Only authorized **Tourism Officers** may assign tour guides and send official request/assignment notifications.

---

## 24. UI/UX Requirements

Preserve the existing visual design. For the multiple destination selector specifically:

- Do not use a confusing native multi-select.
- Keep the existing dropdown style.
- Use clear labels such as `Destination 1`, `Destination 2`, etc.
- Make `+ Add another destination` visually clear but not overwhelming.
- Provide a remove action for additional destinations.
- Prevent duplicate selections.
- Provide clear validation messages.
- Ensure mobile responsiveness.
- Do not create unnecessary scrolling or layout shifts.
- Maintain consistent spacing with the existing form.

---

## 25. Development Rules

Before modifying the system:

1. Inspect the existing project structure.
2. Inspect the current `tour-guide.php`.
3. Inspect the existing destination database/table.
4. Inspect the existing tour guide request tables.
5. Inspect the existing digital receipt implementation.
6. Inspect the current notification/SMS implementation.
7. Inspect the existing Admin sidebar.
8. Inspect the existing authentication and authorization system.
9. Reuse existing components and conventions where possible.
10. Do not duplicate existing functionality.
11. Do not break existing requests or digital receipts.
12. Do not unnecessarily change unrelated pages.
13. Follow the existing PHP/MySQL architecture.
14. Maintain responsive behavior.
15. Test existing functionality after implementing the new features.

---

## 26. Final Visitor Workflow

```text
VISITOR
↓
Request Tour Guide
↓
Select Destination(s)
↓
Date / Time / Number of Visitors
↓
Name / Mobile / Email
(Optional)
↓
Anything the guide should know?
(Optional)
↓
Submit
↓
Generate Digital Receipt
↓
TOURISM OFFICER
↓
View Tour Guide Request
↓
Send Message #1
"Request received..."
↓
Check Available Tour Guides
↓
Select Tour Guide
↓
Assign Tour Guide & Notify Visitor
↓
Send Message #2
"Your Tour Guide is..."
↓
VISITOR
↓
Presents Digital Receipt
↓
Meets Assigned Tour Guide
```

---

## 27. Final Admin Workflow

```text
ADMIN
↓
Tour Guide Management
↓
Add Tour Guide
↓
Basic Information
↓
Profile Picture
↓
Credentials
↓
Certificates
↓
Validity
↓
Status
↓
Generate Tour Guide ID
↓
Front + Back ID
↓
QR Code on Front
↓
QR Verification Page
```

---

## 28. Final System Objective

Create a **centralized, professional, and verifiable Tour Guide Management system** that integrates with the existing tour guide request process.

The final system should provide:

- Multiple destinations per tour request
- One reference number per request
- Existing digital receipt functionality
- Two-stage visitor notifications
- Centralized tour guide profiles
- Credentials and certificate management
- Tour guide availability checking
- Tour guide assignment
- Automatically generated front/back Tour Guide IDs
- One QR code on the front of the ID
- Online QR-based Tour Guide verification
- Real-time validity/status checking
- Secure document management
- Minimal disruption to the existing system

> **Most importantly: preserve the existing system and build these features as an enhancement, not as a complete rewrite.**
