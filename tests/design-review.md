# Public programme redesign — November 16, 2026

## Research

- Nielsen Norman Group, *AI Prototyping in Real Design Contexts*: its evaluation found that general-purpose outputs can miss context, hierarchy, grouping, contrast and spacing. https://www.nngroup.com/articles/ai-prototyping/
- GOV.UK, *Writing for user interfaces*: keep language concise, lead with useful information and write headings and links that describe their purpose. https://www.gov.uk/service-manual/design/writing-for-user-interfaces
- Shin et al., *Interrogating Design Homogenization in Web Vibe Coding* (2026 preprint): discusses risks of reproducing common design conventions and the value of deliberate design decisions. https://arxiv.org/abs/2603.13036

These informed a design review, not an AI-authorship detector. A layout, font or colour scheme alone does not establish who created a website.

## Observations about the previous HIC page

The oversized promotional slogan could apply to many events. The decorative “one shared purpose” count did not help participants make a decision. Repeated rounded sections added length. Secondary timetable text was small. On narrow screens, the responsible person and duration required horizontal scrolling.

## Implemented changes

- Lead with the actual bootcamp name, county and dates.
- Use a printed-programme visual structure: clear rules, consistent spacing, restrained red and green, explicit section names.
- Put the field departure in a compact notice with the existing synchronized countdown.
- Remove the promotional closing section and decorative statistics.
- Make day selection obvious without turning every item into a card.
- Use larger session text and show every mobile row's time, responsible person and length together.
- Timestamp organizer notices and keep all participant copy specific to the event.
- Preserve all 21 agenda entries, clean URLs, live notices, countdown target and frontend hot updates.

## Verification

Preview and production headless Chromium checks covered all three days (9/5/7 entries), 1440px desktop, 390px and 320px layouts, visible mobile table details, keyboard tabs and skip link. An already-open production document adopted the redesigned template/styles/script without navigating and preserved its selected day. Timer synchronization, fullscreen and clean URLs were rechecked separately. No schedule records were edited by the redesign.
