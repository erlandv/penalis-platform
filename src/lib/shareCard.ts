/**
 * Pure Canvas 2D generator for shareable Penalis social cards.
 *
 * Produces retina-resolution PNG cards (1080×1920 for Instagram Stories / WA
 * Status, 1080×1080 for Instagram Feed / Threads / X) completely client-side
 * with zero third-party rendering dependencies and zero server load.
 *
 * Shared by the writer dashboard, the public `/kalimat-hari-ini/` showcase,
 * and public `/author/<username>/` author archives.
 */

export type ShareCardFormat = 'story' | 'feed';
export type ShareCardTheme = 'kertas' | 'senja' | 'malam';

export interface ShareCardBrandAssets {
  logoUrl?: string;
  iconUrl?: string;
}

export interface QuoteShareCardPayload {
  kind: 'quote';
  text: string;
  displayName: string;
  username: string;
  avatarUrl?: string;
  authorUrl?: string;
  isVerified?: boolean;
  isSenator?: boolean;
  siteUrl?: string;
}

export interface ProfileShareCardPayload {
  kind: 'profile';
  displayName: string;
  username: string;
  avatarUrl?: string;
  profileUrl: string;
  bio?: string;
  postCount: number;
  totalViews?: number | null;
  joinedLabel?: string;
  isVerified?: boolean;
  isSenator?: boolean;
}

export interface ArticleShareCardPayload {
  kind: 'article';
  id?: number;
  title: string;
  excerpt?: string;
  category?: string;
  coverUrl?: string;
  articleUrl: string;
  publishedLabel?: string;
  displayName: string;
  username: string;
  avatarUrl?: string;
  isVerified?: boolean;
  isSenator?: boolean;
}

export type ShareCardPayload =
  | QuoteShareCardPayload
  | ProfileShareCardPayload
  | ArticleShareCardPayload;

export interface FormatSpec {
  id: ShareCardFormat;
  label: string;
  ratioLabel: string;
  width: number;
  height: number;
}

export interface ThemeSpec {
  id: ShareCardTheme;
  label: string;
  swatch: string;
  bgStart: string;
  bgMid: string;
  bgEnd: string;
  glowA: string;
  glowB: string;
  cardBg: string;
  cardBorder: string;
  cardShadow: string;
  coverStart: string;
  coverMid: string;
  coverEnd: string;
  textPrimary: string;
  textSecondary: string;
  textMuted: string;
  accent: string;
  accentSoft: string;
  accentBorder: string;
  divider: string;
  statBg: string;
  statBorder: string;
  pillBg: string;
  pillText: string;
  footerText: string;
  watermarkAlpha: number;
}

export const SHARE_CARD_FORMATS: readonly FormatSpec[] = [
  { id: 'story', label: 'Story', ratioLabel: '9:16', width: 1080, height: 1920 },
  { id: 'feed', label: 'Feed', ratioLabel: '1:1', width: 1080, height: 1080 },
] as const;

export const SHARE_CARD_THEMES: readonly ThemeSpec[] = [
  {
    id: 'kertas',
    label: 'Kertas',
    swatch: '#F7F3EB',
    bgStart: '#FBF9F5',
    bgMid: '#F5F0E7',
    bgEnd: '#EFE8DC',
    glowA: 'rgba(236, 183, 49, 0.14)',
    glowB: 'rgba(124, 58, 237, 0.08)',
    cardBg: '#FFFFFF',
    cardBorder: 'rgba(24, 24, 27, 0.08)',
    cardShadow: 'rgba(24, 24, 27, 0.08)',
    coverStart: '#F3EEFF',
    coverMid: '#FAF5EC',
    coverEnd: '#FDF1D6',
    textPrimary: '#18181B',
    textSecondary: '#3F3F46',
    textMuted: '#6B7280',
    accent: '#7C3AED',
    accentSoft: 'rgba(124, 58, 237, 0.09)',
    accentBorder: 'rgba(124, 58, 237, 0.22)',
    divider: 'rgba(24, 24, 27, 0.08)',
    statBg: '#FAF8F5',
    statBorder: 'rgba(24, 24, 27, 0.07)',
    pillBg: 'rgba(124, 58, 237, 0.08)',
    pillText: '#6D28D9',
    footerText: '#71717A',
    watermarkAlpha: 0.06,
  },
  {
    id: 'senja',
    label: 'Senja',
    swatch: '#EDE4FF',
    bgStart: '#EFE8FF',
    bgMid: '#F8F3F0',
    bgEnd: '#FCEFD7',
    glowA: 'rgba(124, 58, 237, 0.16)',
    glowB: 'rgba(236, 183, 49, 0.22)',
    cardBg: 'rgba(255, 255, 255, 0.94)',
    cardBorder: 'rgba(124, 58, 237, 0.14)',
    cardShadow: 'rgba(88, 28, 135, 0.09)',
    coverStart: '#EAE0FF',
    coverMid: '#F6EEFC',
    coverEnd: '#FDECC8',
    textPrimary: '#1C1917',
    textSecondary: '#44403C',
    textMuted: '#6B6673',
    accent: '#7C3AED',
    accentSoft: 'rgba(124, 58, 237, 0.11)',
    accentBorder: 'rgba(124, 58, 237, 0.24)',
    divider: 'rgba(124, 58, 237, 0.11)',
    statBg: '#FAF8FF',
    statBorder: 'rgba(124, 58, 237, 0.11)',
    pillBg: 'rgba(124, 58, 237, 0.1)',
    pillText: '#6D28D9',
    footerText: '#6B6673',
    watermarkAlpha: 0.07,
  },
  {
    id: 'malam',
    label: 'Malam',
    swatch: '#161822',
    bgStart: '#0D0F16',
    bgMid: '#131622',
    bgEnd: '#1B1926',
    glowA: 'rgba(124, 58, 237, 0.24)',
    glowB: 'rgba(236, 183, 49, 0.14)',
    cardBg: 'rgba(255, 255, 255, 0.055)',
    cardBorder: 'rgba(255, 255, 255, 0.12)',
    cardShadow: 'rgba(0, 0, 0, 0.42)',
    coverStart: 'rgba(124, 58, 237, 0.34)',
    coverMid: 'rgba(168, 85, 247, 0.16)',
    coverEnd: 'rgba(236, 183, 49, 0.24)',
    textPrimary: '#F8FAFC',
    textSecondary: '#D4D4D8',
    textMuted: '#A1A1AA',
    accent: '#C4B5FD',
    accentSoft: 'rgba(196, 181, 253, 0.14)',
    accentBorder: 'rgba(196, 181, 253, 0.26)',
    divider: 'rgba(255, 255, 255, 0.1)',
    statBg: 'rgba(255, 255, 255, 0.045)',
    statBorder: 'rgba(255, 255, 255, 0.09)',
    pillBg: 'rgba(196, 181, 253, 0.14)',
    pillText: '#DDD6FE',
    footerText: '#A1A1AA',
    watermarkAlpha: 0.08,
  },
] as const;

const DEFAULT_FONT_STACK =
  '"Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';

const GENERIC_FONT_FAMILIES = new Set([
  'serif',
  'sans-serif',
  'monospace',
  'cursive',
  'fantasy',
  'system-ui',
  'ui-sans-serif',
  'ui-serif',
  'ui-monospace',
  'inherit',
]);

const imageCache = new Map<string, Promise<HTMLImageElement | null>>();

/**
 * Resolve the active theme's computed font stack for Canvas 2D drawing.
 *
 * Canvas `ctx.font` cannot use CSS `inherit` directly, so we inspect the
 * computed `font-family` on `document.body` (falling back to Plus Jakarta Sans
 * and system UI fonts) so any future theme typography change flows into
 * generated cards automatically.
 */
export function resolveThemeFontFamily(computedFontFamily?: string): string {
  let raw = typeof computedFontFamily === 'string' ? computedFontFamily.trim() : '';

  if (raw === '' && typeof window !== 'undefined' && typeof document !== 'undefined') {
    const bodyFont = document.body ? window.getComputedStyle(document.body).fontFamily.trim() : '';
    const rootFont = document.documentElement
      ? window.getComputedStyle(document.documentElement).fontFamily.trim()
      : '';
    raw = bodyFont || rootFont;
  }

  if (raw === '') {
    return DEFAULT_FONT_STACK;
  }

  const firstFamily = (raw.split(',')[0] ?? '').replace(/^['"]+|['"]+$/g, '').trim().toLowerCase();
  if (firstFamily === '' || GENERIC_FONT_FAMILIES.has(firstFamily)) {
    return DEFAULT_FONT_STACK;
  }

  return `${raw}, ${DEFAULT_FONT_STACK}`;
}

/**
 * Wait briefly for the resolved theme webfont faces (400–700) to finish loading
 * before Canvas measures and draws text.
 */
async function ensureFontsReady(fontFamily: string): Promise<void> {
  if (typeof document === 'undefined' || !('fonts' in document) || !document.fonts) {
    return;
  }

  const primaryFamily = (fontFamily.split(',')[0] ?? '').trim();
  const loads: Array<Promise<unknown>> = [document.fonts.ready];

  if (primaryFamily !== '' && typeof document.fonts.load === 'function') {
    for (const weight of ['400', '500', '600', '700']) {
      loads.push(document.fonts.load(`${weight} 36px ${primaryFamily}`).catch(() => undefined));
    }
  }

  await Promise.race([
    Promise.all(loads).then(() => undefined),
    new Promise<void>((resolve) => {
      window.setTimeout(resolve, 1200);
    }),
  ]);
}

export function getFormatSpec(format: ShareCardFormat): FormatSpec {
  return SHARE_CARD_FORMATS.find((item) => item.id === format) ?? SHARE_CARD_FORMATS[0]!;
}

export function getThemeSpec(theme: ShareCardTheme): ThemeSpec {
  return SHARE_CARD_THEMES.find((item) => item.id === theme) ?? SHARE_CARD_THEMES[0]!;
}

/**
 * Strip protocol and trailing slash so URLs look crisp on social cards.
 */
export function formatCardUrlLabel(url: string): string {
  const clean = url
    .trim()
    .replace(/^https?:\/\//i, '')
    .replace(/\/+$/, '');
  return clean || 'penalis.com';
}

/**
 * Format integer counts with Indonesian dot thousands separators.
 */
export function formatCardCount(value: number): string {
  const safe = Number.isFinite(value) && value > 0 ? Math.trunc(value) : 0;
  return new Intl.NumberFormat('id-ID').format(safe);
}

/**
 * Extract 1-2 uppercase initials for the CORS-safe fallback avatar monogram.
 */
export function buildInitials(name: string): string {
  const parts = name
    .trim()
    .split(/\s+/)
    .filter((part) => part.length > 0);

  if (parts.length === 0) {
    return 'P';
  }

  const first = Array.from(parts[0] ?? '')[0] ?? 'P';
  if (parts.length === 1) {
    return first.toUpperCase();
  }

  const last = Array.from(parts[parts.length - 1] ?? '')[0] ?? '';
  return `${first}${last}`.toUpperCase();
}

/**
 * Pick an adaptive editorial font size for a #KalimatHariIni quote.
 */
export function pickQuoteFontSize(text: string, format: ShareCardFormat): number {
  const length = Array.from(text.trim()).length;
  const lineBreaks = text.split('\n').length;

  if (format === 'feed') {
    if (length <= 55 && lineBreaks <= 2) {
      return 38;
    }
    if (length <= 120 && lineBreaks <= 3) {
      return 34;
    }
    return 31;
  }

  if (length <= 55 && lineBreaks <= 2) {
    return 42;
  }
  if (length <= 120 && lineBreaks <= 3) {
    return 38;
  }
  return 34;
}

/**
 * Pick an adaptive editorial font size for a published article title.
 */
export function pickArticleTitleFontSize(title: string, format: ShareCardFormat): number {
  const length = Array.from(title.trim()).length;

  if (format === 'feed') {
    if (length <= 36) {
      return 38;
    }
    if (length <= 72) {
      return 33;
    }
    return 29;
  }

  if (length <= 36) {
    return 46;
  }
  if (length <= 72) {
    return 40;
  }
  return 35;
}

/**
 * Word-wrap text into bounded lines using a custom width measurer.
 *
 * Preserves explicit line breaks, gracefully breaks extra-long unspaced tokens,
 * and appends an ellipsis (`…`) when clamped at `maxLines`.
 */
export function wrapCanvasText(
  text: string,
  maxWidth: number,
  maxLines: number,
  measureWidth: (candidate: string) => number,
): string[] {
  const normalized = text.replace(/\r\n?/g, '\n').trim();
  if (normalized === '' || maxLines <= 0) {
    return [];
  }

  const paragraphs = normalized.split('\n');
  const lines: string[] = [];
  let truncated = false;

  const pushToken = (current: string, token: string): { line: string; overflow: string } => {
    const candidate = current === '' ? token : `${current} ${token}`;
    if (measureWidth(candidate) <= maxWidth) {
      return { line: candidate, overflow: '' };
    }

    if (current !== '') {
      return { line: current, overflow: token };
    }

    // Single token wider than maxWidth: slice by code points.
    const chars = Array.from(token);
    let fit = '';
    let idx = 0;
    while (idx < chars.length) {
      const next = fit + (chars[idx] ?? '');
      if (fit !== '' && measureWidth(next) > maxWidth) {
        break;
      }
      fit = next;
      idx += 1;
    }
    return { line: fit, overflow: chars.slice(idx).join('') };
  };

  for (let pIndex = 0; pIndex < paragraphs.length; pIndex += 1) {
    const paragraph = (paragraphs[pIndex] ?? '').trim();
    if (paragraph === '') {
      continue;
    }

    const words = paragraph.split(/\s+/);
    let current = '';

    for (let wIndex = 0; wIndex < words.length; wIndex += 1) {
      let token = words[wIndex] ?? '';
      while (token !== '') {
        const result = pushToken(current, token);
        if (result.overflow === '') {
          current = result.line;
          token = '';
        } else {
          lines.push(result.line);
          if (lines.length >= maxLines) {
            truncated = true;
            break;
          }
          current = '';
          token = result.overflow;
        }
      }

      if (truncated) {
        break;
      }
    }

    if (truncated) {
      break;
    }

    if (current !== '') {
      lines.push(current);
      if (lines.length >= maxLines) {
        const hasMoreParagraphs = paragraphs
          .slice(pIndex + 1)
          .some((rest) => rest.trim() !== '');
        if (hasMoreParagraphs) {
          truncated = true;
        }
        break;
      }
    }
  }

  if (truncated && lines.length > 0) {
    const lastIdx = lines.length - 1;
    let lastLine = (lines[lastIdx] ?? '').replace(/[.,;:!?\s]+$/, '');
    while (lastLine.length > 0 && measureWidth(`${lastLine}…`) > maxWidth) {
      const chars = Array.from(lastLine);
      chars.pop();
      lastLine = chars.join('').trimEnd();
    }
    lines[lastIdx] = `${lastLine}…`;
  }

  return lines.slice(0, maxLines);
}

/**
 * Build a clean downloadable PNG filename for the generated card.
 */
export function buildShareCardFilename(payload: ShareCardPayload, format: ShareCardFormat): string {
  if (payload.kind === 'article') {
    const slug =
      payload.title
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 48)
        .replace(/-+$/, '') || 'karya';
    return `penalis-karya-${slug}-${format}.png`;
  }

  const safeUser =
    payload.username
      .toLowerCase()
      .replace(/[^a-z0-9_-]+/g, '-')
      .replace(/^-+|-+$/g, '') || 'penulis';
  const kindSlug = payload.kind === 'quote' ? 'kalimat-hari-ini' : 'profil';
  return `penalis-${kindSlug}-${safeUser}-${format}.png`;
}

/**
 * Build the secondary clipboard copy action shown inside the Share modal.
 */
export function buildShareCopyContent(payload: ShareCardPayload): {
  label: string;
  copiedLabel: string;
  text: string;
} {
  if (payload.kind === 'profile') {
    return {
      label: 'Salin tautan profil',
      copiedLabel: 'Tautan profil disalin!',
      text: payload.profileUrl,
    };
  }

  if (payload.kind === 'article') {
    return {
      label: 'Salin tautan karya',
      copiedLabel: 'Tautan karya disalin!',
      text: payload.articleUrl,
    };
  }

  const base = `"${payload.text}"\n— ${payload.displayName} (#KalimatHariIni)`;
  const suffix = payload.authorUrl ? `\n${payload.authorUrl}` : '';
  return {
    label: 'Salin kutipan',
    copiedLabel: 'Kutipan disalin!',
    text: `${base}${suffix}`,
  };
}

/**
 * Load an image safely for Canvas drawing without tainting the canvas.
 *
 * Returns `null` if the URL is empty, fails to load, or lacks CORS headers.
 */
function loadCanvasImage(url: string | undefined): Promise<HTMLImageElement | null> {
  const cleanUrl = (url ?? '').trim();
  if (cleanUrl === '' || typeof Image === 'undefined') {
    return Promise.resolve(null);
  }

  const cached = imageCache.get(cleanUrl);
  if (cached) {
    return cached;
  }

  const promise = new Promise<HTMLImageElement | null>((resolve) => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.decoding = 'async';

    const timer = window.setTimeout(() => {
      resolve(null);
    }, 6000);

    img.onload = () => {
      window.clearTimeout(timer);
      if (img.naturalWidth > 0 && img.naturalHeight > 0) {
        resolve(img);
      } else {
        resolve(null);
      }
    };
    img.onerror = () => {
      window.clearTimeout(timer);
      resolve(null);
    };

    img.src = cleanUrl;
  });

  imageCache.set(cleanUrl, promise);
  return promise;
}

function drawRoundedRectPath(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  r: number,
): void {
  const radius = Math.min(r, w / 2, h / 2);
  ctx.beginPath();
  ctx.moveTo(x + radius, y);
  ctx.arcTo(x + w, y, x + w, y + h, radius);
  ctx.arcTo(x + w, y + h, x, y + h, radius);
  ctx.arcTo(x, y + h, x, y, radius);
  ctx.arcTo(x, y, x + w, y, radius);
  ctx.closePath();
}

function drawBackdrop(
  ctx: CanvasRenderingContext2D,
  width: number,
  height: number,
  theme: ThemeSpec,
): void {
  const bg = ctx.createLinearGradient(0, 0, width, height);
  bg.addColorStop(0, theme.bgStart);
  bg.addColorStop(0.52, theme.bgMid);
  bg.addColorStop(1, theme.bgEnd);
  ctx.fillStyle = bg;
  ctx.fillRect(0, 0, width, height);

  // Two subtle ambient corner blooms for depth without clutter.
  const glowTop = ctx.createRadialGradient(
    width * 0.15,
    height * 0.14,
    0,
    width * 0.15,
    height * 0.14,
    width * 0.72,
  );
  glowTop.addColorStop(0, theme.glowA);
  glowTop.addColorStop(1, 'rgba(0,0,0,0)');
  ctx.fillStyle = glowTop;
  ctx.fillRect(0, 0, width, height);

  const glowBottom = ctx.createRadialGradient(
    width * 0.88,
    height * 0.86,
    0,
    width * 0.88,
    height * 0.86,
    width * 0.72,
  );
  glowBottom.addColorStop(0, theme.glowB);
  glowBottom.addColorStop(1, 'rgba(0,0,0,0)');
  ctx.fillStyle = glowBottom;
  ctx.fillRect(0, 0, width, height);
}

function drawBrandLogo(
  ctx: CanvasRenderingContext2D,
  logoImg: HTMLImageElement | null,
  centerX: number,
  topY: number,
  targetHeight: number,
  theme: ThemeSpec,
  fontFamily: string,
): void {
  if (logoImg) {
    const ratio = logoImg.naturalWidth / logoImg.naturalHeight;
    const drawW = targetHeight * ratio;
    ctx.drawImage(logoImg, centerX - drawW / 2, topY, drawW, targetHeight);
    return;
  }

  // Fallback wordmark if logo.png failed to load.
  ctx.save();
  ctx.font = `800 ${Math.round(targetHeight * 0.88)}px ${fontFamily}`;
  ctx.fillStyle = theme.id === 'malam' ? '#F3C14B' : '#E5AE2B';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'top';
  ctx.fillText('Penalis', centerX, topY);
  ctx.restore();
}

function drawAvatar(
  ctx: CanvasRenderingContext2D,
  avatarImg: HTMLImageElement | null,
  displayName: string,
  cx: number,
  cy: number,
  radius: number,
  ringColor: string,
  ringWidth: number,
  fontFamily: string,
): void {
  ctx.save();
  if (ringWidth > 0) {
    ctx.beginPath();
    ctx.arc(cx, cy, radius + ringWidth, 0, Math.PI * 2);
    ctx.fillStyle = ringColor;
    ctx.fill();
  }

  ctx.beginPath();
  ctx.arc(cx, cy, radius, 0, Math.PI * 2);
  ctx.closePath();
  ctx.clip();

  if (avatarImg) {
    // Center-crop cover fill inside circular clip.
    const size = radius * 2;
    const scale = Math.max(size / avatarImg.naturalWidth, size / avatarImg.naturalHeight);
    const drawW = avatarImg.naturalWidth * scale;
    const drawH = avatarImg.naturalHeight * scale;
    ctx.drawImage(avatarImg, cx - drawW / 2, cy - drawH / 2, drawW, drawH);
  } else {
    const grad = ctx.createLinearGradient(cx - radius, cy - radius, cx + radius, cy + radius);
    grad.addColorStop(0, '#7C3AED');
    grad.addColorStop(1, '#D97706');
    ctx.fillStyle = grad;
    ctx.fillRect(cx - radius, cy - radius, radius * 2, radius * 2);

    ctx.font = `700 ${Math.round(radius * 0.82)}px ${fontFamily}`;
    ctx.fillStyle = '#FFFFFF';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(buildInitials(displayName), cx, cy + 2);
  }

  ctx.restore();
}

function drawVerifiedBadge(ctx: CanvasRenderingContext2D, cx: number, cy: number, size: number): void {
  const r = size / 2;
  ctx.save();
  ctx.beginPath();
  ctx.arc(cx, cy, r, 0, Math.PI * 2);
  ctx.fillStyle = '#1D9BF0';
  ctx.fill();

  ctx.strokeStyle = '#FFFFFF';
  ctx.lineWidth = Math.max(2.2, size * 0.11);
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  ctx.beginPath();
  ctx.moveTo(cx - r * 0.42, cy + r * 0.04);
  ctx.lineTo(cx - r * 0.1, cy + r * 0.36);
  ctx.lineTo(cx + r * 0.46, cy - r * 0.3);
  ctx.stroke();
  ctx.restore();
}

function drawSenatorBadge(ctx: CanvasRenderingContext2D, cx: number, cy: number, size: number): void {
  const outerR = size * 0.52;
  const innerR = outerR * 0.46;
  ctx.save();
  ctx.beginPath();
  for (let i = 0; i < 10; i += 1) {
    const angle = (Math.PI / 5) * i - Math.PI / 2;
    const radius = i % 2 === 0 ? outerR : innerR;
    const x = cx + Math.cos(angle) * radius;
    const y = cy + Math.sin(angle) * radius;
    if (i === 0) {
      ctx.moveTo(x, y);
    } else {
      ctx.lineTo(x, y);
    }
  }
  ctx.closePath();
  ctx.fillStyle = '#F59E0B';
  ctx.fill();
  ctx.restore();
}

function drawCardShell(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  radius: number,
  theme: ThemeSpec,
): void {
  ctx.save();
  ctx.shadowColor = theme.cardShadow;
  ctx.shadowBlur = 44;
  ctx.shadowOffsetY = 18;
  drawRoundedRectPath(ctx, x, y, w, h, radius);
  ctx.fillStyle = theme.cardBg;
  ctx.fill();
  ctx.restore();

  ctx.save();
  drawRoundedRectPath(ctx, x, y, w, h, radius);
  ctx.strokeStyle = theme.cardBorder;
  ctx.lineWidth = 2;
  ctx.stroke();
  ctx.restore();
}

function drawIconWatermark(
  ctx: CanvasRenderingContext2D,
  iconImg: HTMLImageElement | null,
  cardX: number,
  cardY: number,
  cardW: number,
  cardH: number,
  cardRadius: number,
  theme: ThemeSpec,
): void {
  if (!iconImg) {
    return;
  }

  ctx.save();
  drawRoundedRectPath(ctx, cardX, cardY, cardW, cardH, cardRadius);
  ctx.clip();
  ctx.globalAlpha = theme.watermarkAlpha;
  const markSize = Math.round(Math.min(cardW, cardH) * 0.34);
  ctx.drawImage(iconImg, cardX + cardW - markSize * 0.78, cardY + cardH - markSize * 0.76, markSize, markSize);
  ctx.restore();
}

function drawNameWithBadges(
  ctx: CanvasRenderingContext2D,
  name: string,
  centerX: number,
  baselineY: number,
  maxWidth: number,
  fontSize: number,
  color: string,
  isVerified: boolean,
  isSenator: boolean,
  align: 'left' | 'center',
  fontFamily: string,
): void {
  const badgeSize = Math.round(fontSize * 0.72);
  const badgeGap = Math.round(fontSize * 0.24);
  const badgeCount = (isVerified ? 1 : 0) + (isSenator ? 1 : 0);
  const badgesWidth = badgeCount > 0 ? badgeCount * badgeSize + badgeCount * badgeGap : 0;

  ctx.save();
  ctx.font = `700 ${fontSize}px ${fontFamily}`;
  ctx.fillStyle = color;
  ctx.textBaseline = 'middle';
  ctx.textAlign = 'left';

  const maxNameWidth = Math.max(120, maxWidth - badgesWidth);
  let display = name.trim();
  if (ctx.measureText(display).width > maxNameWidth) {
    const chars = Array.from(display);
    while (chars.length > 1 && ctx.measureText(`${chars.join('')}…`).width > maxNameWidth) {
      chars.pop();
    }
    display = `${chars.join('').trimEnd()}…`;
  }

  const measuredName = ctx.measureText(display).width;
  const totalWidth = measuredName + badgesWidth;
  const startX = align === 'center' ? centerX - totalWidth / 2 : centerX;

  ctx.fillText(display, startX, baselineY);

  let badgeX = startX + measuredName + badgeGap + badgeSize / 2;
  if (isVerified) {
    drawVerifiedBadge(ctx, badgeX, baselineY, badgeSize);
    badgeX += badgeSize + badgeGap;
  }
  if (isSenator) {
    drawSenatorBadge(ctx, badgeX, baselineY, badgeSize);
  }
  ctx.restore();
}

function drawQuoteCard(
  ctx: CanvasRenderingContext2D,
  payload: QuoteShareCardPayload,
  format: FormatSpec,
  theme: ThemeSpec,
  logoImg: HTMLImageElement | null,
  iconImg: HTMLImageElement | null,
  avatarImg: HTMLImageElement | null,
  fontFamily: string,
): void {
  const { width, height } = format;
  const isStory = format.id === 'story';

  drawBackdrop(ctx, width, height, theme);

  // Top brand logo with safe area for Instagram Story chrome.
  const logoTopY = isStory ? 186 : 64;
  const logoHeight = isStory ? 66 : 52;
  drawBrandLogo(ctx, logoImg, width / 2, logoTopY, logoHeight, theme, fontFamily);

  // Measure quote layout so the card wraps tightly with generous editorial margins.
  const cardMarginX = isStory ? 84 : 74;
  const cardW = width - cardMarginX * 2;
  const padX = isStory ? 68 : 58;
  const padTop = isStory ? 64 : 50;
  const padBottom = isStory ? 52 : 42;
  const contentW = cardW - padX * 2;

  const quoteFontSize = pickQuoteFontSize(payload.text, format.id);
  const quoteLineHeight = Math.round(quoteFontSize * 1.54);

  ctx.save();
  ctx.font = `400 ${quoteFontSize}px ${fontFamily}`;
  const quoteLines = wrapCanvasText(payload.text, contentW, isStory ? 9 : 7, (s) => ctx.measureText(s).width);
  ctx.restore();

  const avatarRadius = isStory ? 42 : 36;
  const headHeight = avatarRadius * 2;
  const gapAfterHead = isStory ? 46 : 36;
  const textBlockH = Math.max(1, quoteLines.length) * quoteLineHeight;
  const gapBeforeTag = isStory ? 26 : 20;
  const tagH = Math.round(quoteFontSize * 1.35);
  const gapBeforeDivider = isStory ? 48 : 36;
  const footH = isStory ? 42 : 36;

  const minCardH = isStory ? 600 : 490;
  const maxCardH = isStory ? 1140 : 760;
  const naturalCardH =
    padTop + headHeight + gapAfterHead + textBlockH + gapBeforeTag + tagH + gapBeforeDivider + footH + padBottom;
  const cardH = Math.max(minCardH, Math.min(maxCardH, naturalCardH));

  const areaTop = logoTopY + logoHeight + (isStory ? 64 : 38);
  const areaBottom = height - (isStory ? 210 : 88);
  const cardY = Math.round(areaTop + Math.max(0, (areaBottom - areaTop - cardH) / 2));
  const cardX = cardMarginX;
  const cardRadius = isStory ? 42 : 34;

  drawCardShell(ctx, cardX, cardY, cardW, cardH, cardRadius, theme);
  drawIconWatermark(ctx, iconImg, cardX, cardY, cardW, cardH, cardRadius, theme);

  // Author header inside card
  const avatarCx = cardX + padX + avatarRadius;
  const avatarCy = cardY + padTop + avatarRadius;
  drawAvatar(ctx, avatarImg, payload.displayName, avatarCx, avatarCy, avatarRadius, theme.cardBg, 0, fontFamily);

  const identityX = avatarCx + avatarRadius + 24;
  const identityMaxW = cardX + cardW - padX - identityX;
  drawNameWithBadges(
    ctx,
    payload.displayName,
    identityX,
    avatarCy - 15,
    identityMaxW,
    isStory ? 33 : 29,
    theme.textPrimary,
    Boolean(payload.isVerified),
    Boolean(payload.isSenator),
    'left',
    fontFamily,
  );

  ctx.save();
  ctx.font = `500 ${isStory ? 25 : 22}px ${fontFamily}`;
  ctx.fillStyle = theme.textMuted;
  ctx.textAlign = 'left';
  ctx.textBaseline = 'middle';
  ctx.fillText(`@${payload.username}`, identityX, avatarCy + 21);
  ctx.restore();

  // Quote body
  const quoteStartY = cardY + padTop + headHeight + gapAfterHead;
  ctx.save();
  ctx.font = `400 ${quoteFontSize}px ${fontFamily}`;
  ctx.fillStyle = theme.textPrimary;
  ctx.textAlign = 'left';
  ctx.textBaseline = 'top';
  quoteLines.forEach((line, index) => {
    ctx.fillText(line, cardX + padX, quoteStartY + index * quoteLineHeight);
  });
  ctx.restore();

  // Hashtag accent (matches quote body font size)
  const tagY = quoteStartY + textBlockH + gapBeforeTag;
  ctx.save();
  ctx.font = `600 ${quoteFontSize}px ${fontFamily}`;
  ctx.fillStyle = theme.accent;
  ctx.textAlign = 'left';
  ctx.textBaseline = 'top';
  ctx.fillText('#KalimatHariIni', cardX + padX, tagY);
  ctx.restore();

  // Card footer inside card
  const dividerY = cardY + cardH - padBottom - footH - 18;
  ctx.save();
  ctx.strokeStyle = theme.divider;
  ctx.lineWidth = 2;
  ctx.beginPath();
  ctx.moveTo(cardX + padX, dividerY);
  ctx.lineTo(cardX + cardW - padX, dividerY);
  ctx.stroke();

  const footY = dividerY + 18 + footH / 2;
  ctx.font = `500 ${isStory ? 24 : 21}px ${fontFamily}`;
  ctx.fillStyle = theme.textMuted;
  ctx.textAlign = 'left';
  ctx.textBaseline = 'middle';
  ctx.fillText('Apa kalimatmu hari ini?', cardX + padX, footY);

  const siteLabel = formatCardUrlLabel(payload.siteUrl || payload.authorUrl || 'penalis.com');
  ctx.font = `600 ${isStory ? 24 : 21}px ${fontFamily}`;
  ctx.fillStyle = theme.accent;
  ctx.textAlign = 'right';
  ctx.fillText(siteLabel, cardX + cardW - padX, footY);
  ctx.restore();

  // Bottom ambient caption (above IG Story reply bar)
  const footerCaptionY = isStory ? height - 138 : height - 46;
  ctx.save();
  ctx.font = `500 ${isStory ? 25 : 21}px ${fontFamily}`;
  ctx.fillStyle = theme.footerText;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText('Menambah kebermanfaatan yang datang dari tulisan', width / 2, footerCaptionY);
  ctx.restore();
}

function drawProfileCard(
  ctx: CanvasRenderingContext2D,
  payload: ProfileShareCardPayload,
  format: FormatSpec,
  theme: ThemeSpec,
  logoImg: HTMLImageElement | null,
  iconImg: HTMLImageElement | null,
  avatarImg: HTMLImageElement | null,
  fontFamily: string,
): void {
  const { width, height } = format;
  const isStory = format.id === 'story';

  drawBackdrop(ctx, width, height, theme);

  // Top Penalis logo
  const logoTopY = isStory ? 186 : 56;
  const logoHeight = isStory ? 66 : 48;
  drawBrandLogo(ctx, logoImg, width / 2, logoTopY, logoHeight, theme, fontFamily);

  const cardMarginX = isStory ? 84 : 74;
  const cardW = width - cardMarginX * 2;
  const padX = isStory ? 66 : 56;
  const contentW = cardW - padX * 2;
  const cardRadius = isStory ? 44 : 36;

  // Measure bio lines
  const bioFontSize = isStory ? 29 : 24;
  const bioLineHeight = Math.round(bioFontSize * 1.48);
  ctx.save();
  ctx.font = `400 ${bioFontSize}px ${fontFamily}`;
  const bioLines = payload.bio
    ? wrapCanvasText(payload.bio, contentW - 16, isStory ? 3 : 2, (s) => ctx.measureText(s).width)
    : [];
  ctx.restore();

  const coverH = isStory ? 176 : 124;
  const avatarRadius = isStory ? 84 : 68;
  const avatarRing = isStory ? 10 : 8;
  const nameFontSize = isStory ? 46 : 38;
  const handleFontSize = isStory ? 28 : 24;
  const joinedFontSize = isStory ? 24 : 21;
  const hasJoined = Boolean(payload.joinedLabel && payload.joinedLabel.trim() !== '');
  const hasViews = typeof payload.totalViews === 'number' && Number.isFinite(payload.totalViews);

  const bioBlockH = bioLines.length > 0 ? bioLines.length * bioLineHeight + (isStory ? 36 : 24) : 0;
  const joinedBlockH = hasJoined ? (isStory ? 44 : 34) : 0;
  const statsBoxH = isStory ? 136 : 112;
  const urlPillH = isStory ? 64 : 52;
  const padBottom = isStory ? 56 : 40;

  const naturalCardH =
    coverH +
    avatarRadius +
    (isStory ? 46 : 34) +
    nameFontSize +
    (isStory ? 18 : 14) +
    handleFontSize +
    joinedBlockH +
    (isStory ? 34 : 24) +
    bioBlockH +
    statsBoxH +
    (isStory ? 40 : 28) +
    urlPillH +
    padBottom;

  const cardH = Math.min(isStory ? 1240 : 815, Math.max(isStory ? 960 : 720, naturalCardH));
  const areaTop = logoTopY + logoHeight + (isStory ? 58 : 30);
  const areaBottom = height - (isStory ? 196 : 74);
  const cardY = Math.round(areaTop + Math.max(0, (areaBottom - areaTop - cardH) / 2));
  const cardX = cardMarginX;

  drawCardShell(ctx, cardX, cardY, cardW, cardH, cardRadius, theme);

  // Subtle top cover banner clipped cleanly to card rounded corners
  ctx.save();
  drawRoundedRectPath(ctx, cardX, cardY, cardW, cardH, cardRadius);
  ctx.clip();
  const coverGrad = ctx.createLinearGradient(cardX, cardY, cardX + cardW, cardY + coverH);
  coverGrad.addColorStop(0, theme.coverStart);
  coverGrad.addColorStop(0.55, theme.coverMid);
  coverGrad.addColorStop(1, theme.coverEnd);
  ctx.fillStyle = coverGrad;
  ctx.fillRect(cardX, cardY, cardW, coverH);
  ctx.restore();

  drawIconWatermark(ctx, iconImg, cardX, cardY, cardW, cardH, cardRadius, theme);

  // Centered overlapping avatar
  const centerX = width / 2;
  const avatarCy = cardY + coverH;
  drawAvatar(
    ctx,
    avatarImg,
    payload.displayName,
    centerX,
    avatarCy,
    avatarRadius,
    theme.cardBg,
    avatarRing,
    fontFamily,
  );

  // Display name + badges
  let cursorY = avatarCy + avatarRadius + (isStory ? 52 : 40);
  drawNameWithBadges(
    ctx,
    payload.displayName,
    centerX,
    cursorY,
    contentW,
    nameFontSize,
    theme.textPrimary,
    Boolean(payload.isVerified),
    Boolean(payload.isSenator),
    'center',
    fontFamily,
  );

  // @username
  cursorY += Math.round(nameFontSize * 0.72) + (isStory ? 18 : 14);
  ctx.save();
  ctx.font = `500 ${handleFontSize}px ${fontFamily}`;
  ctx.fillStyle = theme.textMuted;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(`@${payload.username}`, centerX, cursorY);

  if (hasJoined) {
    cursorY += isStory ? 40 : 32;
    ctx.font = `500 ${joinedFontSize}px ${fontFamily}`;
    ctx.fillStyle = theme.textMuted;
    ctx.fillText(`Bergabung ${payload.joinedLabel}`, centerX, cursorY);
  }
  ctx.restore();

  // Bio
  if (bioLines.length > 0) {
    cursorY += isStory ? 44 : 32;
    ctx.save();
    ctx.font = `400 ${bioFontSize}px ${fontFamily}`;
    ctx.fillStyle = theme.textSecondary;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    bioLines.forEach((line, index) => {
      ctx.fillText(line, centerX, cursorY + index * bioLineHeight);
    });
    ctx.restore();
    cursorY += bioLines.length * bioLineHeight;
  }

  // Stats strip + URL pill anchored cleanly toward bottom of card
  const urlPillY = cardY + cardH - padBottom - urlPillH;
  const statsY = urlPillY - (isStory ? 38 : 26) - statsBoxH;

  const stats: Array<{ value: string; label: string }> = [
    { value: formatCardCount(payload.postCount), label: 'Karya Terbit' },
  ];
  if (hasViews) {
    stats.push({ value: formatCardCount(payload.totalViews ?? 0), label: 'Total Views' });
  }

  ctx.save();
  drawRoundedRectPath(ctx, cardX + padX, statsY, contentW, statsBoxH, 24);
  ctx.fillStyle = theme.statBg;
  ctx.fill();
  ctx.strokeStyle = theme.statBorder;
  ctx.lineWidth = 2;
  ctx.stroke();

  const colW = contentW / stats.length;
  stats.forEach((stat, index) => {
    const colCx = cardX + padX + colW * index + colW / 2;

    if (index > 0) {
      const divX = cardX + padX + colW * index;
      ctx.strokeStyle = theme.divider;
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.moveTo(divX, statsY + 24);
      ctx.lineTo(divX, statsY + statsBoxH - 24);
      ctx.stroke();
    }

    ctx.font = `700 ${isStory ? 42 : 34}px ${fontFamily}`;
    ctx.fillStyle = theme.textPrimary;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(stat.value, colCx, statsY + statsBoxH * 0.38);

    ctx.font = `500 ${isStory ? 23 : 20}px ${fontFamily}`;
    ctx.fillStyle = theme.textMuted;
    ctx.fillText(stat.label, colCx, statsY + statsBoxH * 0.72);
  });
  ctx.restore();

  // Profile URL pill
  const urlLabel = formatCardUrlLabel(payload.profileUrl);
  ctx.save();
  const pillFontSize = isStory ? 25 : 22;
  ctx.font = `600 ${pillFontSize}px ${fontFamily}`;
  const textW = ctx.measureText(urlLabel).width;
  const pillW = Math.min(contentW, textW + 64);
  const pillX = centerX - pillW / 2;

  drawRoundedRectPath(ctx, pillX, urlPillY, pillW, urlPillH, urlPillH / 2);
  ctx.fillStyle = theme.pillBg;
  ctx.fill();
  ctx.strokeStyle = theme.accentBorder;
  ctx.lineWidth = 1.5;
  ctx.stroke();

  ctx.fillStyle = theme.pillText;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  let displayUrl = urlLabel;
  if (textW > pillW - 44) {
    const chars = Array.from(displayUrl);
    while (chars.length > 4 && ctx.measureText(`${chars.join('')}…`).width > pillW - 44) {
      chars.pop();
    }
    displayUrl = `${chars.join('')}…`;
  }
  ctx.fillText(displayUrl, centerX, urlPillY + urlPillH / 2 + 1);
  ctx.restore();

  // Bottom tagline
  const footerCaptionY = isStory ? height - 134 : height - 40;
  ctx.save();
  ctx.font = `500 ${isStory ? 25 : 21}px ${fontFamily}`;
  ctx.fillStyle = theme.footerText;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText('Tempat karya tulis dirayakan · penalis.com', width / 2, footerCaptionY);
  ctx.restore();
}

function drawArticleCard(
  ctx: CanvasRenderingContext2D,
  payload: ArticleShareCardPayload,
  format: FormatSpec,
  theme: ThemeSpec,
  logoImg: HTMLImageElement | null,
  iconImg: HTMLImageElement | null,
  avatarImg: HTMLImageElement | null,
  coverImg: HTMLImageElement | null,
  fontFamily: string,
): void {
  const { width, height } = format;
  const isStory = format.id === 'story';

  drawBackdrop(ctx, width, height, theme);

  // Top Penalis logo
  const logoTopY = isStory ? 168 : 48;
  const logoHeight = isStory ? 62 : 46;
  drawBrandLogo(ctx, logoImg, width / 2, logoTopY, logoHeight, theme, fontFamily);

  const cardMarginX = isStory ? 80 : 68;
  const cardW = width - cardMarginX * 2;
  const padX = isStory ? 56 : 48;
  const contentW = cardW - padX * 2;
  const cardRadius = isStory ? 40 : 32;

  const hasCover = coverImg !== null;
  const coverH = hasCover ? (isStory ? 430 : 300) : isStory ? 196 : 124;

  const categoryText = (payload.category ?? '').trim();
  const hasCategory = categoryText !== '';
  const catFontSize = isStory ? 23 : 19;
  const catPillH = hasCategory ? (isStory ? 44 : 36) : 0;
  const gapAfterCover = isStory ? 42 : 30;
  const gapAfterCat = hasCategory ? (isStory ? 22 : 16) : 0;

  const titleText = payload.title.trim() || 'Tanpa judul';
  const titleFontSize = pickArticleTitleFontSize(titleText, format.id);
  const titleLineHeight = Math.round(titleFontSize * 1.28);
  ctx.save();
  ctx.font = `700 ${titleFontSize}px ${fontFamily}`;
  const titleLines = wrapCanvasText(titleText, contentW, isStory ? 3 : 2, (s) => ctx.measureText(s).width);
  ctx.restore();

  const excerptText = (payload.excerpt ?? '').trim();
  const excerptFontSize = isStory ? 27 : 22;
  const excerptLineHeight = Math.round(excerptFontSize * 1.54);
  ctx.save();
  ctx.font = `400 ${excerptFontSize}px ${fontFamily}`;
  const excerptLines =
    excerptText !== ''
      ? wrapCanvasText(excerptText, contentW, isStory ? 3 : 2, (s) => ctx.measureText(s).width)
      : [];
  ctx.restore();

  const titleBlockH = Math.max(1, titleLines.length) * titleLineHeight;
  const gapBeforeExcerpt = excerptLines.length > 0 ? (isStory ? 22 : 16) : 0;
  const excerptBlockH = excerptLines.length * excerptLineHeight;
  const gapBeforeDivider = isStory ? 44 : 32;
  const avatarRadius = isStory ? 32 : 26;
  const authorRowH = avatarRadius * 2;
  const padBottom = isStory ? 44 : 34;

  const naturalCardH =
    coverH +
    gapAfterCover +
    catPillH +
    gapAfterCat +
    titleBlockH +
    gapBeforeExcerpt +
    excerptBlockH +
    gapBeforeDivider +
    20 +
    authorRowH +
    padBottom;

  const maxCardH = isStory ? 1320 : 842;
  const minCardH = isStory ? 880 : 660;
  const cardH = Math.min(maxCardH, Math.max(minCardH, naturalCardH));
  const areaTop = logoTopY + logoHeight + (isStory ? 52 : 26);
  const areaBottom = height - (isStory ? 188 : 68);
  const cardY = Math.round(areaTop + Math.max(0, (areaBottom - areaTop - cardH) / 2));
  const cardX = cardMarginX;

  drawCardShell(ctx, cardX, cardY, cardW, cardH, cardRadius, theme);

  // Featured cover image or editorial gradient header clipped to card corners
  ctx.save();
  drawRoundedRectPath(ctx, cardX, cardY, cardW, cardH, cardRadius);
  ctx.clip();

  if (coverImg) {
    const scale = Math.max(cardW / coverImg.naturalWidth, coverH / coverImg.naturalHeight);
    const drawW = coverImg.naturalWidth * scale;
    const drawH = coverImg.naturalHeight * scale;
    const imgX = cardX + (cardW - drawW) / 2;
    const imgY = cardY + (coverH - drawH) / 2;

    ctx.save();
    ctx.beginPath();
    ctx.rect(cardX, cardY, cardW, coverH);
    ctx.clip();
    ctx.drawImage(coverImg, imgX, imgY, drawW, drawH);

    // Subtle bottom scrim for a calm transition into the card surface
    const scrim = ctx.createLinearGradient(0, cardY + coverH * 0.65, 0, cardY + coverH);
    scrim.addColorStop(0, 'rgba(0, 0, 0, 0)');
    scrim.addColorStop(1, 'rgba(0, 0, 0, 0.08)');
    ctx.fillStyle = scrim;
    ctx.fillRect(cardX, cardY, cardW, coverH);
    ctx.restore();
  } else {
    const coverGrad = ctx.createLinearGradient(cardX, cardY, cardX + cardW, cardY + coverH);
    coverGrad.addColorStop(0, theme.coverStart);
    coverGrad.addColorStop(0.55, theme.coverMid);
    coverGrad.addColorStop(1, theme.coverEnd);
    ctx.fillStyle = coverGrad;
    ctx.fillRect(cardX, cardY, cardW, coverH);

    if (iconImg) {
      ctx.save();
      ctx.globalAlpha = theme.id === 'malam' ? 0.2 : 0.16;
      const mark = Math.round(coverH * 0.56);
      ctx.drawImage(iconImg, cardX + (cardW - mark) / 2, cardY + (coverH - mark) / 2, mark, mark);
      ctx.restore();
    }
  }
  ctx.restore();

  drawIconWatermark(ctx, iconImg, cardX, cardY, cardW, cardH, cardRadius, theme);

  let cursorY = cardY + coverH + gapAfterCover;

  // Category pill
  if (hasCategory) {
    ctx.save();
    ctx.font = `600 ${catFontSize}px ${fontFamily}`;
    const catW = Math.min(contentW, ctx.measureText(categoryText).width + (isStory ? 40 : 32));
    drawRoundedRectPath(ctx, cardX + padX, cursorY, catW, catPillH, catPillH / 2);
    ctx.fillStyle = theme.accentSoft;
    ctx.fill();
    ctx.strokeStyle = theme.accentBorder;
    ctx.lineWidth = 1.5;
    ctx.stroke();

    ctx.fillStyle = theme.pillText;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    ctx.fillText(categoryText, cardX + padX + (isStory ? 20 : 16), cursorY + catPillH / 2 + 1);
    ctx.restore();
    cursorY += catPillH + gapAfterCat;
  }

  // Title
  ctx.save();
  ctx.font = `700 ${titleFontSize}px ${fontFamily}`;
  ctx.fillStyle = theme.textPrimary;
  ctx.textAlign = 'left';
  ctx.textBaseline = 'top';
  titleLines.forEach((line, index) => {
    ctx.fillText(line, cardX + padX, cursorY + index * titleLineHeight);
  });
  ctx.restore();
  cursorY += titleBlockH + gapBeforeExcerpt;

  // Excerpt
  if (excerptLines.length > 0) {
    ctx.save();
    ctx.font = `400 ${excerptFontSize}px ${fontFamily}`;
    ctx.fillStyle = theme.textSecondary;
    ctx.textAlign = 'left';
    ctx.textBaseline = 'top';
    excerptLines.forEach((line, index) => {
      ctx.fillText(line, cardX + padX, cursorY + index * excerptLineHeight);
    });
    ctx.restore();
  }

  // Author footer anchored to bottom of card
  const dividerY = cardY + cardH - padBottom - authorRowH - 22;
  ctx.save();
  ctx.strokeStyle = theme.divider;
  ctx.lineWidth = 2;
  ctx.beginPath();
  ctx.moveTo(cardX + padX, dividerY);
  ctx.lineTo(cardX + cardW - padX, dividerY);
  ctx.stroke();
  ctx.restore();

  const avatarCx = cardX + padX + avatarRadius;
  const avatarCy = dividerY + 22 + avatarRadius;
  drawAvatar(ctx, avatarImg, payload.displayName, avatarCx, avatarCy, avatarRadius, theme.cardBg, 0, fontFamily);

  const rightLabel = (payload.publishedLabel ?? '').trim();
  let rightLabelW = 0;
  if (rightLabel !== '') {
    ctx.save();
    ctx.font = `500 ${isStory ? 23 : 20}px ${fontFamily}`;
    rightLabelW = ctx.measureText(rightLabel).width + 24;
    ctx.fillStyle = theme.textMuted;
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';
    ctx.fillText(rightLabel, cardX + cardW - padX, avatarCy);
    ctx.restore();
  }

  const identityX = avatarCx + avatarRadius + 18;
  const identityMaxW = Math.max(160, cardX + cardW - padX - identityX - rightLabelW);
  drawNameWithBadges(
    ctx,
    payload.displayName,
    identityX,
    avatarCy - 12,
    identityMaxW,
    isStory ? 28 : 24,
    theme.textPrimary,
    Boolean(payload.isVerified),
    Boolean(payload.isSenator),
    'left',
    fontFamily,
  );

  ctx.save();
  ctx.font = `500 ${isStory ? 22 : 19}px ${fontFamily}`;
  ctx.fillStyle = theme.textMuted;
  ctx.textAlign = 'left';
  ctx.textBaseline = 'middle';
  ctx.fillText(`@${payload.username}`, identityX, avatarCy + 17);
  ctx.restore();

  // Bottom tagline
  const footerCaptionY = isStory ? height - 126 : height - 36;
  ctx.save();
  ctx.font = `500 ${isStory ? 25 : 21}px ${fontFamily}`;
  ctx.fillStyle = theme.footerText;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText('Baca selengkapnya di penalis.com', width / 2, footerCaptionY);
  ctx.restore();
}

/**
 * Render a quote, profile, or article share card onto the supplied `<canvas>` element.
 */
export async function renderShareCard(
  canvas: HTMLCanvasElement,
  payload: ShareCardPayload,
  options: {
    format: ShareCardFormat;
    theme: ShareCardTheme;
    brand?: ShareCardBrandAssets;
  },
): Promise<void> {
  const format = getFormatSpec(options.format);
  const theme = getThemeSpec(options.theme);
  const fontFamily = resolveThemeFontFamily();

  canvas.width = format.width;
  canvas.height = format.height;

  const ctx = canvas.getContext('2d');
  if (!ctx) {
    throw new Error('Canvas 2D context is unavailable.');
  }

  const [logoImg, iconImg, avatarImg, coverImg] = await Promise.all([
    loadCanvasImage(options.brand?.logoUrl),
    loadCanvasImage(options.brand?.iconUrl),
    loadCanvasImage(payload.avatarUrl),
    payload.kind === 'article' ? loadCanvasImage(payload.coverUrl) : Promise.resolve(null),
    ensureFontsReady(fontFamily),
  ]);

  if (payload.kind === 'quote') {
    drawQuoteCard(ctx, payload, format, theme, logoImg, iconImg, avatarImg, fontFamily);
  } else if (payload.kind === 'article') {
    drawArticleCard(ctx, payload, format, theme, logoImg, iconImg, avatarImg, coverImg, fontFamily);
  } else {
    drawProfileCard(ctx, payload, format, theme, logoImg, iconImg, avatarImg, fontFamily);
  }
}

/**
 * Export a rendered `<canvas>` to a PNG `Blob`.
 */
export function exportShareCardBlob(canvas: HTMLCanvasElement): Promise<Blob> {
  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => {
        if (blob) {
          resolve(blob);
        } else {
          reject(new Error('Gagal membuat file gambar PNG.'));
        }
      },
      'image/png',
      1,
    );
  });
}

/**
 * Whether the current browser supports Web Share API Level 2 with PNG files.
 */
export function canNativeShareFiles(): boolean {
  if (
    typeof navigator === 'undefined' ||
    typeof navigator.share !== 'function' ||
    typeof navigator.canShare !== 'function' ||
    typeof File === 'undefined'
  ) {
    return false;
  }

  try {
    const sample = new File([''], 'penalis.png', { type: 'image/png' });
    return navigator.canShare({ files: [sample] });
  } catch {
    return false;
  }
}

/**
 * Trigger a browser download for a generated PNG card.
 */
export function downloadCardImage(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.style.display = 'none';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  window.setTimeout(() => {
    URL.revokeObjectURL(url);
  }, 1000);
}

/**
 * Share a generated PNG card via the OS share sheet (Instagram Stories, WA, etc.).
 *
 * Returns `'shared'`, `'cancelled'`, or `'unsupported'`.
 */
export async function shareCardImage(
  blob: Blob,
  filename: string,
  title: string,
  text: string,
): Promise<'shared' | 'cancelled' | 'unsupported'> {
  if (!canNativeShareFiles()) {
    return 'unsupported';
  }

  const file = new File([blob], filename, { type: 'image/png' });
  try {
    await navigator.share({ files: [file], title, text });
    return 'shared';
  } catch (caught: unknown) {
    if (caught instanceof DOMException && caught.name === 'AbortError') {
      return 'cancelled';
    }
    throw caught;
  }
}
