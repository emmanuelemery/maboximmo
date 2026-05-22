param(
  [Parameter(Mandatory = $true)][string]$SqlFile,
  [Parameter(Mandatory = $true)][string[]]$Tables
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Get-CreateTableBlock {
  param(
    [Parameter(Mandatory = $true)][string]$Path,
    [Parameter(Mandatory = $true)][string]$Table
  )

  # Éviter les backticks dans une chaîne interpolée PowerShell (le backtick est un escape).
  $pattern = '^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`' + [regex]::Escape($Table) + '`\s*\('
  $startMatch = Select-String -Path $Path -Pattern $pattern
  if (-not $startMatch) { return $null }

  $startLine = $startMatch.LineNumber
  $lines = Get-Content -Path $Path

  $buf = New-Object System.Collections.Generic.List[string]
  for ($i = $startLine - 1; $i -lt $lines.Count; $i++) {
    $line = $lines[$i]
    $buf.Add($line)
    if ($line -match "^\s*\)\s*ENGINE=" -or $line -match "^\s*\)\s*DEFAULT\s+CHARSET=" -or $line -match "^\s*\)\s*;" ) {
      break
    }
  }
  return @{
    start_line = $startLine
    lines      = $buf.ToArray()
  }
}

function Parse-CreateTable {
  param(
    [Parameter(Mandatory = $true)]
    [AllowEmptyCollection()]
    [AllowEmptyString()]
    [string[]]$Lines
  )

  $columns = @()
  $indexes = @()
  $constraints = @()

  foreach ($raw in $Lines) {
    if ($null -eq $raw) { continue }
    $line = $raw.Trim()
    if ($line -eq '') { continue }

    # Column
    if ($line -match '^`(?<name>[^`]+)`\s+(?<type>[^,]+?)(?<tail>,?)$') {
      $columns += [ordered]@{
        name = $Matches['name']
        type = ($Matches['type'].Trim())
      }
      continue
    }

    # Primary / Unique / Key
    if ($line -match '^(PRIMARY KEY|UNIQUE KEY|KEY)\s+') {
      $indexes += $line.TrimEnd(',')
      continue
    }

    # Foreign key / constraint
    if ($line -match '^(CONSTRAINT)\s+') {
      $constraints += $line.TrimEnd(',')
      continue
    }
  }

  return [ordered]@{
    columns     = $columns
    indexes     = $indexes
    constraints = $constraints
  }
}

function Emit-Markdown {
  param(
    [Parameter(Mandatory = $true)][string]$Table,
    [Parameter(Mandatory = $true)][int]$StartLine,
    [Parameter(Mandatory = $true)][hashtable]$Parsed
  )

  $out = New-Object System.Collections.Generic.List[string]
  # Utiliser des guillemets simples pour inclure des backticks littéraux (Markdown) sans interférence avec l'escape PowerShell.
  $out.Add(('### `{0}` (source: `{1}:{2}`)' -f $Table, $SqlFile, $StartLine))

  if ($Parsed.columns.Count -gt 0) {
    $out.Add("")
    $out.Add("**Colonnes**")
    foreach ($c in $Parsed.columns) {
      $out.Add(('- `{0}` — `{1}`' -f $c.name, $c.type))
    }
  }

  if ($Parsed.indexes.Count -gt 0) {
    $out.Add("")
    $out.Add("**Index / clés**")
    foreach ($idx in $Parsed.indexes) {
      $out.Add(('- `{0}`' -f $idx))
    }
  }

  if ($Parsed.constraints.Count -gt 0) {
    $out.Add("")
    $out.Add("**Contraintes (FK)**")
    foreach ($fk in $Parsed.constraints) {
      $out.Add(('- `{0}`' -f $fk))
    }
  }

  $out.Add("")
  return $out.ToArray() -join "`n"
}

if (-not (Test-Path -LiteralPath $SqlFile)) {
  throw "Fichier SQL introuvable: $SqlFile"
}

$md = New-Object System.Collections.Generic.List[string]
foreach ($t in $Tables) {
  $block = Get-CreateTableBlock -Path $SqlFile -Table $t
  if (-not $block) {
    $md.Add(('### `{0}`' -f $t))
    $md.Add("")
    $md.Add(('_Non trouvé dans `{0}`_' -f $SqlFile))
    $md.Add("")
    continue
  }
  $parsed = Parse-CreateTable -Lines $block.lines
  $md.Add((Emit-Markdown -Table $t -StartLine ([int]$block.start_line) -Parsed $parsed))
}

$md -join "`n"
