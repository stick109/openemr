# Week 2 — TODO for the user

Items that genuinely require a human and cannot be done by an AI coding
agent with shell + file access. Bar is high. Most "user" tasks turn out to
be agent-doable; everything that is goes into [`PLAN.md`](PLAN.md) instead.

If you find yourself doing something on this list that an agent could have
done, move it to `PLAN.md`.

---

## 1. Demo video (3–5 min) — primary item

Record the Week 2 demo following the run-of-show in [`DEMO.md`](DEMO.md).

- [ ] Record the 5-minute walkthrough hitting all six demo moments in order:
      document upload → extraction → evidence retrieval → citations
      (click-to-source / bbox overlay) → eval results → observability.
- [ ] Narrate over the screen capture (mic check before recording).
- [ ] Show a regression failure mid-demo — run the
      `--inject-regression wrong-value` command from `EVAL.md` §3 live, or
      replay the saved failure output.
- [ ] Upload to a durable host (YouTube unlisted, Loom, Vimeo, or similar).
- [ ] Paste the resulting URL into `README.md` and `week-2/SETUP.md`.

Why this is user-only: video recording requires a physical microphone, a
human voice narrating, and a person operating the screen capture in real
time. None of that is reproducible by an AI agent.

---

## 2. Anything paid that requires a personal payment method

If submission requires a hosting tier upgrade (Railway pro, Honeycomb pro,
custom domain) the user must enter their own payment details. The agent is
prohibited from entering payment data on the user's behalf.

- [ ] Decide whether the free tier is sufficient for the submission window;
      upgrade only if it isn't.

---

## 3. Identity / submission portal sign-off

If the Gauntlet submission portal requires a manual click-through agreement,
ID verification, or a "submit" button on a portal page that the user is
logged in to:

- [ ] Click "Submit" on the official Gauntlet submission portal once
      everything in `PLAN.md` is green and the demo video URL is live.
