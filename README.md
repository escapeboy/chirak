# Chirak

*Чирак* — apprentice. A master model teaches a small apprentice model one craft until it can do it alone.

Chirak turns a task description and a handful of examples into a small, self-hosted specialist model.
It freezes an eval set first, measures the untouched base model, synthesizes training data,
LoRA fine-tunes on an AMD Instinct MI300X, analyses failures and regenerates data for the weak
classes until a deterministic stop rule fires.

Built for the AMD Developer Hackathon ACT III, track *Reinvent Commerce*.

**Status:** environment spike (`spike/`). Nothing below the spike is built yet.

## What predates the event

Research notes and the environment spike scripts only. All product code (worker, control plane,
orchestrator) is written during the event, 12–17 Oct 2026, and the commit log shows it.

## License

MIT
