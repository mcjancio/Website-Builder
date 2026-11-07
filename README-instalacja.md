# Website Builder 2026 — instalacja (Claude + ChatGPT)

Oba warianty korzystają z tej samej treści v2.0. Skill dla Claude dzieli ją na moduły ładowane na żądanie (progressive disclosure — model czyta tylko potrzebną sekcję, oszczędza kontekst); dla ChatGPT kompaktowe instrukcje (7 651 znaków) + pełny plik jako Knowledge.

## Pliki w paczce

| Plik | Do czego |
|---|---|
| `website-builder-2026.skill` | Skill dla Claude (SKILL.md + 7 plików references) |
| `chatgpt-custom-gpt-website-builder.md` | Konfiguracja Custom GPT + gotowy blok Instructions |
| `master-website-builder-prompt-2026-v2.md` | Źródło (z poprzedniej wiadomości) — wgraj jako Knowledge do GPT |

## Claude

**Claude.ai (aplikacja / web):** kliknij kartę pliku `.skill` w tej rozmowie — jeśli Twój plan/organizacja pozwala na tworzenie skilli, zobaczysz przycisk **Save skill**, który instaluje go w Twoim profilu. Szczegóły i limity: support.claude.com.

**Claude Code:** rozpakuj archiwum (to zwykły ZIP) do `~/.claude/skills/` (globalnie) albo `.claude/skills/` w repo projektu:

```bash
unzip website-builder-2026.skill -d ~/.claude/skills/
```

**API / Agent SDK:** skille można też podpiąć programistycznie — aktualna dokumentacja: docs.claude.com.

Po instalacji skill uruchamia się sam, gdy poprosisz o stronę ("zrób mi landing page…", "make me a site for…", wzmianka o hostilo.app).

## ChatGPT

Postępuj według `chatgpt-custom-gpt-website-builder.md`: Custom GPT (Instructions + Knowledge) albo ChatGPT Projects z tym samym blokiem instrukcji.

## Różnice w praktyce

- **Claude (skill):** modułowość — pełny kod §1–§11 w references, więc nic nie jest przycięte; workflow, mapa branż i checklista zawsze pod ręką.
- **ChatGPT (GPT):** instrukcje musiały zmieścić się w 8 000 znaków, więc pełne fundamenty kodu żyją w pliku Knowledge — GPT ma polecenie sięgać do niego przed generowaniem kodu. Jakość zależy od tego, czy retrieval faktycznie odczyta sekcje, dlatego przy dłuższych sesjach warto przypomnieć: „trzymaj się knowledge file, sekcja §X".

## Aktualizacje

Źródłem prawdy jest `master-website-builder-prompt-2026-v2.md`. Po zmianach: zaktualizuj pliki w folderze skilla i spakuj ponownie (ZIP z folderem `website-builder-2026/` w środku, rozszerzenie `.skill`), a w GPT podmień plik Knowledge.
