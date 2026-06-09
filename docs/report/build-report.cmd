@echo off
REM ===========================================================================
REM  Build the appendix HTML pages for the CYOA Maker report.
REM
REM  The main narrative pages (index, overview, walkthrough, ai-process,
REM  reflections, appendix-stories) are hand-written HTML and are NOT built here.
REM
REM  This script converts the prepared Markdown copies in .\appendix\ to HTML
REM  using report-template.html5 (which pulls in report.css + header.js +
REM  lightbox). The .md copies already live in .\appendix\; note that
REM  appendix\ai-prompt-assembly.md has had its Mermaid blocks swapped for the
REM  .svg diagrams in the same folder.
REM
REM  Run from docs\report\ :  build-report.cmd
REM ===========================================================================

REM  Titles / pagetitles live in each .md's YAML front-matter so the doc title
REM  renders above the table of contents. --toc-depth=3 shows section + subsection.

setlocal
set TPL=report-template.html5
set OPTS=-s --template=%TPL% --toc --toc-depth=3

pandoc appendix\architecture.md         -o appendix\architecture.html         %OPTS%
pandoc appendix\implementation-plan.md  -o appendix\implementation-plan.html  %OPTS%

echo.
echo Done. Built 2 appendix pages in .\appendix\
endlocal
