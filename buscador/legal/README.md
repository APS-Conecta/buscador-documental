# Legal gate package (A-SEC-7)

Status: **drafts for review. Nothing here is legal advice or a sign-off.** ADR-0006 stays "open" until the owners fill in its table with real references.

| File | For | What it is |
|---|---|---|
| `counsel-brief.md` | counsel | what the system does with personal data, the questions that need an answer, the options |
| `dpia-draft.md` | the data protection owner | a first Data Protection Impact Assessment built from the actual design and tests: data flows, risks, the controls that exist and where they are proven |
| `notice-draft.md` | counsel and the establishment | the staff notice (Spanish), a draft of the text the first-run notice in the administration page points to |
| `decision-record.md` | the establishment | the form that records the decisions and the references; filling it is what lets ADR-0006 be superseded |

How the gate closes: the owners review the drafts, change what is wrong, sign the decision record outside this repository (or attach its reference), and someone with authority gives the references to the maintainer, who copies them into the table in ADR-0006 and marks A-SEC-7 met in `buscador/acceptance.md`.
