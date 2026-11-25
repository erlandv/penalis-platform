import { describe, expect, it } from 'vitest';
import {
  buildInitials,
  buildProfileCardStats,
  buildShareCardFilename,
  buildShareCopyContent,
  formatCardCount,
  formatCardUrlLabel,
  getFormatSpec,
  getThemeSpec,
  pickArticleTitleFontSize,
  pickQuoteFontSize,
  resolveThemeFontFamily,
  SHARE_CARD_FORMATS,
  SHARE_CARD_THEMES,
  wrapCanvasText,
} from './shareCard';

describe('shareCard pure helpers', () => {
  it('defines Story (9:16) and Feed (1:1) formats at 1080px retina width', () => {
    expect(SHARE_CARD_FORMATS.map((f) => f.id)).toEqual(['story', 'feed']);
    expect(getFormatSpec('story')).toMatchObject({ width: 1080, height: 1920, ratioLabel: '9:16' });
    expect(getFormatSpec('feed')).toMatchObject({ width: 1080, height: 1080, ratioLabel: '1:1' });
  });

  it('provides three curated editorial themes', () => {
    expect(SHARE_CARD_THEMES.map((t) => t.id)).toEqual(['kertas', 'senja', 'malam']);
    expect(getThemeSpec('malam').id).toBe('malam');
  });

  it('formats URL labels without protocol or trailing slashes', () => {
    expect(formatCardUrlLabel('https://penalis.com/author/erland/')).toBe('penalis.com/author/erland');
    expect(formatCardUrlLabel('http://localhost:2121/kalimat-hari-ini///')).toBe(
      'localhost:2121/kalimat-hari-ini',
    );
    expect(formatCardUrlLabel('   ')).toBe('penalis.com');
  });

  it('formats counts using Indonesian thousand separators', () => {
    expect(formatCardCount(0)).toBe('0');
    expect(formatCardCount(-5)).toBe('0');
    expect(formatCardCount(45337)).toBe('45.337');
  });

  it('builds profile card stats for works, optional views, and optional likes', () => {
    expect(buildProfileCardStats({ postCount: 22 })).toEqual([
      { value: '22', label: 'Karya Terbit' },
    ]);

    expect(
      buildProfileCardStats({
        postCount: 22,
        totalViews: 45692,
        totalLikes: null,
      }),
    ).toEqual([
      { value: '22', label: 'Karya Terbit' },
      { value: '45.692', label: 'Total Views' },
    ]);

    expect(
      buildProfileCardStats({
        postCount: 219,
        totalViews: 556729,
        totalLikes: 0,
      }),
    ).toEqual([
      { value: '219', label: 'Karya Terbit' },
      { value: '556.729', label: 'Total Views' },
      { value: '0', label: 'Total Likes' },
    ]);
  });

  it('extracts uppercase initials for fallback monogram avatars', () => {
    expect(buildInitials('Erland Ramdhani')).toBe('ER');
    expect(buildInitials('Siti Nur Haliza')).toBe('SH');
    expect(buildInitials('Nadia')).toBe('N');
    expect(buildInitials('   ')).toBe('P');
  });

  it('scales quote typography based on length and format', () => {
    const shortStory = pickQuoteFontSize('Kita gak punya apa-apa selain semangat!', 'story');
    const longStory = pickQuoteFontSize('a'.repeat(180), 'story');
    const shortFeed = pickQuoteFontSize('Kita gak punya apa-apa selain semangat!', 'feed');

    expect(shortStory).toBeGreaterThan(longStory);
    expect(shortStory).toBeGreaterThan(shortFeed);
  });

  it('scales article title typography based on title length and format', () => {
    const shortStory = pickArticleTitleFontSize('Wayang Topeng', 'story');
    const longStory = pickArticleTitleFontSize(
      'Catatan Panjang tentang Ingatan, Kota yang Berubah, dan Puisi di Ujung Senja',
      'story',
    );
    const shortFeed = pickArticleTitleFontSize('Wayang Topeng', 'feed');

    expect(shortStory).toBeGreaterThan(longStory);
    expect(shortStory).toBeGreaterThan(shortFeed);
  });

  it('wraps text across lines, respects explicit line breaks, and clamps with ellipsis', () => {
    const measure = (text: string) => text.length * 10;

    expect(wrapCanvasText('Baris satu\nBaris dua', 200, 4, measure)).toEqual([
      'Baris satu',
      'Baris dua',
    ]);

    const clamped = wrapCanvasText(
      'Satu dua tiga empat lima enam tujuh delapan sembilan sepuluh',
      120,
      2,
      measure,
    );
    expect(clamped).toHaveLength(2);
    expect(clamped[1]?.endsWith('…')).toBe(true);
    expect(measure(clamped[1] ?? '')).toBeLessThanOrEqual(120);

    const brokenToken = wrapCanvasText('abcdefghijklmnop', 50, 4, measure);
    expect(brokenToken.length).toBeGreaterThan(2);
    expect(brokenToken.every((line) => measure(line) <= 50)).toBe(true);
  });

  it('inherits theme font families while falling back to Plus Jakarta Sans', () => {
    expect(resolveThemeFontFamily('"Plus Jakarta Sans", sans-serif')).toContain('"Plus Jakarta Sans"');
    expect(resolveThemeFontFamily('"Merriweather", Georgia, serif')).toMatch(/^"Merriweather"/);
    expect(resolveThemeFontFamily('sans-serif')).toMatch(/^"Plus Jakarta Sans"/);
    expect(resolveThemeFontFamily('')).toMatch(/^"Plus Jakarta Sans"/);
  });

  it('builds safe PNG filenames for profile and quote cards', () => {
    expect(
      buildShareCardFilename(
        {
          kind: 'profile',
          displayName: 'Erland Ramdhani',
          username: 'Erland.Dev',
          profileUrl: 'https://penalis.com/author/erland/',
          postCount: 22,
        },
        'story',
      ),
    ).toBe('penalis-profil-erland-dev-story.png');

    expect(
      buildShareCardFilename(
        {
          kind: 'quote',
          text: 'Halo',
          displayName: 'Nadia',
          username: 'nadia',
        },
        'feed',
      ),
    ).toBe('penalis-kalimat-hari-ini-nadia-feed.png');

    expect(
      buildShareCardFilename(
        {
          kind: 'article',
          title: 'Wayang Topeng: Catatan Senja!',
          articleUrl: 'https://penalis.com/wayang-topeng/',
          displayName: 'Vitri Fauziah',
          username: 'vitrifauziah',
        },
        'story',
      ),
    ).toBe('penalis-karya-wayang-topeng-catatan-senja-story.png');
  });

  it('builds copy-to-clipboard metadata for profile, quote, and article share modals', () => {
    expect(
      buildShareCopyContent({
        kind: 'profile',
        displayName: 'Erland Ramdhani',
        username: 'erland',
        profileUrl: 'https://penalis.com/author/erland/',
        postCount: 22,
      }),
    ).toEqual({
      label: 'Salin tautan profil',
      copiedLabel: 'Tautan profil disalin!',
      text: 'https://penalis.com/author/erland/',
    });

    expect(
      buildShareCopyContent({
        kind: 'quote',
        text: 'Kita gak punya apa-apa selain semangat',
        displayName: 'Erland Ramdhani',
        username: 'erland',
        authorUrl: 'https://penalis.com/author/erland/',
      }),
    ).toEqual({
      label: 'Salin kutipan',
      copiedLabel: 'Kutipan disalin!',
      text: '"Kita gak punya apa-apa selain semangat"\n— Erland Ramdhani (#KalimatHariIni)\nhttps://penalis.com/author/erland/',
    });

    expect(
      buildShareCopyContent({
        kind: 'article',
        title: 'Wayang Topeng',
        articleUrl: 'https://penalis.com/wayang-topeng/',
        displayName: 'Vitri Fauziah',
        username: 'vitrifauziah',
      }),
    ).toEqual({
      label: 'Salin tautan karya',
      copiedLabel: 'Tautan karya disalin!',
      text: 'https://penalis.com/wayang-topeng/',
    });
  });
});
