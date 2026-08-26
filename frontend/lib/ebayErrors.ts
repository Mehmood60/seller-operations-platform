/**
 * eBay reports a missing required field in several different phrasings depending on
 * whether it is an item specific, a structured field (EAN/UPC), or a category aspect.
 * Missing any one of them makes "Fix with AI" give up with nothing to fill.
 */
const MISSING_FIELD_PATTERNS: RegExp[] = [
  // "The item specific Farbe is missing"                       (required aspect)
  /The item specific ([\s\S]+?) is missing/gi,
  // "The EAN field is missing"                                 (code 21919301)
  /The ([^.<>]+?) (?:field|value) is missing/gi,
  // "Please add EAN to the listing" / "Add Farbe to this listing"
  /(?:Please a|A)dd ([^.<>]+?) to (?:this|the) listing/gi,
  // "Missing required field: EAN"
  /Missing (?:required )?(?:field|aspect)s?:?\s*([^.<>]+)/gi,
];

/** Words eBay wraps around a field name that are not part of it. */
const STRIP_PREFIX = /^(?:the|a|an|required)\s+/i;

/**
 * Pull the field names out of an eBay error message.
 * Returns them de-duplicated, in the order they appear.
 */
export function extractMissingFields(message: string): string[] {
  if (!message) return [];

  const found: string[] = [];
  const seen = new Set<string>();

  for (const pattern of MISSING_FIELD_PATTERNS) {
    // Patterns are module-level and stateful with /g — reset before each use.
    pattern.lastIndex = 0;
    let m: RegExpExecArray | null;
    while ((m = pattern.exec(message)) !== null) {
      for (const part of m[1].split(/,| and /)) {
        const name = part.trim().replace(STRIP_PREFIX, '').replace(/["'.]/g, '').trim();
        // eBay never names a field with a full sentence; guard against greedy matches.
        if (!name || name.length > 40 || seen.has(name.toLowerCase())) continue;
        seen.add(name.toLowerCase());
        found.push(name);
      }
    }
  }

  return found;
}

/** True when the message is eBay complaining about a missing field we can offer to fill. */
export function hasMissingFieldError(message: string | null | undefined): boolean {
  return !!message && extractMissingFields(message).length > 0;
}
