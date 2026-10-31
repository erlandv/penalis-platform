/**
 * Small accessibility helpers.
 *
 * Kept out of `components/ui.tsx` because that file must only export React
 * components for Fast Refresh to work.
 */

/**
 * Build an `aria-describedby` value from a list of element IDs.
 *
 * A field usually has two descriptions: a persistent hint and, when present,
 * an error message. Screen readers announce them in the order given, so the
 * hint comes first and the error second.
 *
 * Returning `undefined` for an empty list keeps the attribute off the element
 * entirely instead of emitting an empty `aria-describedby=""`, which some
 * assistive technologies treat as a description that points nowhere.
 *
 * @param ids Element IDs, optionally falsy.
 * @return Space-separated ID list, or undefined when none are present.
 */
export function describedBy(...ids: Array<string | false | null | undefined>): string | undefined {
  const list = ids.filter((id): id is string => Boolean(id));

  return list.length > 0 ? list.join(' ') : undefined;
}
