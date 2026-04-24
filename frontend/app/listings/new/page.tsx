'use client';

import { useState, useRef } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import {
  ArrowLeft, Sparkles, Loader2, AlertCircle, X,
  Languages, Upload, ImageIcon, Search, CheckCircle, ExternalLink,
} from 'lucide-react';
import { ai as aiApi, listings as listingsApi } from '@/lib/api';
import { RichTextEditor } from '@/components/RichTextEditor';
import type { RichTextEditorHandle } from '@/components/RichTextEditor';
import type { AiShipping, ShippingOrigin } from '@/types';

// ─── Types ───────────────────────────────────────────────────────────────────

interface FormData {
  title: string;
  condition: string;
  description: string;
  price: string;
  quantity: string;
  sku: string;
  shipping_origin: ShippingOrigin;
  shipping: AiShipping;
  category_suggestion: string;
  category_id: string;
  keywords: string[];
  item_specifics: ItemSpecific[];
  images: string[];
}

interface CategorySuggestion { id: string; name: string; percent: number }
interface ItemSpecific { _key: string; name: string; value: string }

const newSpecific = (name = '', value = ''): ItemSpecific =>
  ({ _key: Math.random().toString(36).slice(2), name, value });

interface Translation { title: string; description: string }

// ─── Presets ─────────────────────────────────────────────────────────────────

const SHIPPING_DE: AiShipping = {
  type: 'free', cost: '0.00', service: 'Standardversand (eBay)',
  processing_days_min: 1, processing_days_max: 2,
  delivery_days_min: 1, delivery_days_max: 2,
};

const SHIPPING_CN: AiShipping = {
  type: 'paid', cost: '3.99', service: 'AliExpress Standardversand',
  processing_days_min: 5, processing_days_max: 7,
  delivery_days_min: 15, delivery_days_max: 25,
};

const EMPTY: FormData = {
  title: '', condition: 'Neu', description: '', price: '',
  quantity: '1', sku: '', shipping_origin: 'DE',
  shipping: SHIPPING_DE, category_suggestion: '', category_id: '',
  keywords: [], item_specifics: [newSpecific('Marke', 'Ohne Markenzeichen')], images: [],
};

const CONDITIONS = [
  'Neu', 'Neu mit Etikett', 'Neu ohne Etikett',
  'Gebraucht – Wie neu', 'Gebraucht – Gut', 'Gebraucht – Akzeptabel',
];

const ORIGINS: { id: ShippingOrigin; label: string }[] = [
  { id: 'DE', label: '🇩🇪 Deutschland' },
  { id: 'CN', label: '🇨🇳 China' },
  { id: 'UNKNOWN', label: '🌍 Sonstige' },
];

// ─── Page ────────────────────────────────────────────────────────────────────

export default function NewListingPage() {
  const router = useRouter();

  const [tab, setTab]                   = useState<'ai' | 'manual'>('ai');
  const [url, setUrl]                   = useState('');
  const [analyzing, setAnalyzing]       = useState(false);
  const [analyzeError, setAnalyzeError] = useState<string | null>(null);
  const [analyzed, setAnalyzed]         = useState(false);
  const [form, setForm]                 = useState<FormData>(EMPTY);
  const richEditorRef = useRef<RichTextEditorHandle>(null);

  // translate
  const [translating, setTranslating]   = useState(false);
  const [translation, setTranslation]   = useState<Translation | null>(null);
  const [translateError, setTranslateError] = useState<string | null>(null);

  // save draft
  const [saving, setSaving]             = useState(false);
  const [saveError, setSaveError]       = useState<string | null>(null);

  // publish
  const [publishing, setPublishing]             = useState(false);
  const [publishError, setPublishError]         = useState<string | null>(null);
  const [publishedListing, setPublishedListing] = useState<{ listing_url?: string } | null>(null);
  const [solvingAI, setSolvingAI]               = useState(false);
  const [aiSolveError, setAiSolveError]         = useState<string | null>(null);

  // category search
  const [searchingCats, setSearchingCats]       = useState(false);
  const [catSuggestions, setCatSuggestions]     = useState<CategorySuggestion[]>([]);
  const [showCatDrop, setShowCatDrop]           = useState(false);
  const [catSearchError, setCatSearchError]     = useState<string | null>(null);

  // add image
  const [uploadError, setUploadError]   = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);


  // ── helpers ───────────────────────────────────────────────────────────────

  const set = <K extends keyof FormData>(key: K, val: FormData[K]) =>
    setForm(f => ({ ...f, [key]: val }));

  const setShipping = <K extends keyof AiShipping>(key: K, val: AiShipping[K]) =>
    setForm(f => ({ ...f, shipping: { ...f.shipping, [key]: val } }));

  const extractMissing = (msg: string): string[] => {
    const out: string[] = [];
    // Primary: "The item specific <name> is missing"
    const re1 = /The item specific ([\s\S]+?) is missing/gi;
    let m;
    while ((m = re1.exec(msg)) !== null) out.push(m[1].trim());
    if (out.length > 0) return [...new Set(out)];
    // Fallback: "Add <name> to this listing"  (always present in eBay missing-specific messages)
    const re2 = /Add ([^.]+?) to this listing/gi;
    while ((m = re2.exec(msg)) !== null) out.push(m[1].trim());
    return [...new Set(out)];
  };

  const handleSolveWithAI = async () => {
    // Primary: item_specifics rows with empty values (added by publish error handler)
    const emptyFields = form.item_specifics
      .filter(s => s.name.trim() !== '' && s.value.trim() === '')
      .map(s => s.name.trim());

    // Fallback: parse field names out of the error message text
    const fromError = extractMissing(publishError ?? '');

    const missing = emptyFields.length > 0 ? emptyFields : fromError;

    if (missing.length === 0) {
      console.warn('[AI Solve] Could not extract missing fields. publishError:', publishError);
      setAiSolveError('Fehlende Felder konnten nicht erkannt werden. Bitte Felder manuell ausfüllen.');
      return;
    }
    console.log('[AI Solve] Sending missing fields to AI:', missing);
    setSolvingAI(true);
    setAiSolveError(null);
    try {
      const currentSpecifics: Record<string, string> = {};
      form.item_specifics.forEach(s => { currentSpecifics[s.name] = s.value; });
      const res = await aiApi.suggestSpecifics({
        title: form.title,
        description: form.description,
        item_specifics: currentSpecifics,
        missing_fields: missing,
      });
      const suggestions = res.data as Record<string, string>;
      setForm(f => {
        const updated = f.item_specifics.map(s =>
          suggestions[s.name] !== undefined ? { ...s, value: suggestions[s.name] } : s
        );
        const existingNames = new Set(updated.map(s => s.name));
        const toAdd = Object.entries(suggestions)
          .filter(([name]) => !existingNames.has(name))
          .map(([name, value]) => newSpecific(name, value));
        return { ...f, item_specifics: [...updated, ...toAdd] };
      });
    } catch (err: unknown) {
      setAiSolveError(err instanceof Error ? err.message : 'KI konnte die fehlenden Felder nicht ausfüllen.');
    } finally {
      setSolvingAI(false);
    }
  };

  const handleReset = () => {
    setTab('ai');
    setUrl('');
    setAnalyzing(false);
    setAnalyzeError(null);
    setAnalyzed(false);
    setForm(EMPTY);
    richEditorRef.current?.clear();
    setTranslating(false);
    setTranslation(null);
    setTranslateError(null);
    setSaving(false);
    setSaveError(null);
    setPublishing(false);
    setPublishError(null);
    setPublishedListing(null);
    setSearchingCats(false);
    setCatSuggestions([]);
    setShowCatDrop(false);
    setCatSearchError(null);
    setUploadError(null);
  };

  // ── AI analyze ────────────────────────────────────────────────────────────

  const handleAnalyze = async () => {
    if (!url.trim() || analyzing) return;
    setAnalyzing(true);
    setAnalyzeError(null);
    setAnalyzed(false);
    setTranslation(null);
    try {
      const res = await aiApi.analyze(url.trim());
      const s   = res.data.ai_suggestion;
      const raw = res.data.raw_product;
      setCatSuggestions([]);
      setShowCatDrop(false);
      setForm({
        title:               s.title ?? '',
        condition:           s.condition ?? 'Neu',
        description:         s.description ?? '',
        price:               s.price?.value ?? '',
        quantity:            '1',
        sku:                 '',
        shipping_origin:     s.shipping_origin ?? 'UNKNOWN',
        shipping:            SHIPPING_DE,
        category_suggestion: s.category_suggestion ?? '',
        category_id:         '',
        keywords:            s.keywords ?? [],
        item_specifics:      s.item_specifics && Object.keys(s.item_specifics).length > 0
          ? Object.entries(s.item_specifics).map(([name, value]) => newSpecific(name, value as string))
          : [newSpecific('Marke', 'Ohne Markenzeichen')],
        images:              raw.images ?? [],
      });
      richEditorRef.current?.setValue(s.description ?? '');
      setAnalyzed(true);
      // Auto-search eBay category using the AI suggestion
      const catQuery = (s.category_suggestion || s.title || '').trim();
      if (catQuery) void handleSearchCategories(catQuery);
    } catch (err: unknown) {
      setAnalyzeError(err instanceof Error ? err.message : 'Analyse fehlgeschlagen.');
    } finally {
      setAnalyzing(false);
    }
  };

  // ── Translate preview ────────────────────────────────────────────────────

  const handleTranslate = async () => {
    if (!form.title && !form.description) return;
    setTranslating(true);
    setTranslateError(null);
    setTranslation(null);
    try {
      const res = await aiApi.translate(form.title, form.description);
      setTranslation(res.data);
    } catch (err: unknown) {
      setTranslateError(err instanceof Error ? err.message : 'Übersetzung fehlgeschlagen.');
    } finally {
      setTranslating(false);
    }
  };

  // ── Shipping origin change ────────────────────────────────────────────────

  const handleOriginChange = (origin: ShippingOrigin) => {
    const preset = origin === 'DE' ? SHIPPING_DE : origin === 'CN' ? SHIPPING_CN : form.shipping;
    setForm(f => ({ ...f, shipping_origin: origin, shipping: preset }));
  };

  // Auto-set service when toggling free/paid
  const handleFreeToggle = (free: boolean) => {
    setForm(f => ({
      ...f,
      shipping: {
        ...f.shipping,
        type:    free ? 'free' : 'paid',
        cost:    free ? '0.00' : '3.99',
        service: free ? 'Standardversand (eBay)' : (f.shipping_origin === 'CN' ? 'AliExpress Standardversand' : f.shipping.service),
      },
    }));
  };

  // ── Add image from computer ───────────────────────────────────────────────

  const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    setUploadError(null);
    const files = Array.from(e.target.files ?? []);
    const remaining = 12 - form.images.length;
    if (files.length === 0) return;

    const toRead = files.slice(0, remaining);
    const readers: Promise<string>[] = toRead.map(
      file => new Promise((resolve, reject) => {
        if (!file.type.startsWith('image/')) {
          reject(new Error(`"${file.name}" ist kein Bild.`));
          return;
        }
        const reader = new FileReader();
        reader.onload  = () => resolve(reader.result as string);
        reader.onerror = () => reject(new Error(`Fehler beim Lesen von "${file.name}".`));
        reader.readAsDataURL(file);
      })
    );

    Promise.allSettled(readers).then(results => {
      const dataUrls: string[] = [];
      for (const r of results) {
        if (r.status === 'fulfilled') dataUrls.push(r.value);
        else setUploadError(r.reason?.message ?? 'Upload-Fehler');
      }
      if (dataUrls.length) set('images', [...form.images, ...dataUrls]);
    });

    // reset so same file can be re-selected
    e.target.value = '';
  };

  const removeImage = (i: number) =>
    set('images', form.images.filter((_, j) => j !== i));

  // ── Item specifics ────────────────────────────────────────────────────────

  const addSpecific = () =>
    set('item_specifics', [...form.item_specifics, newSpecific()]);

  const updateSpecific = (i: number, field: 'name' | 'value', val: string) =>
    set('item_specifics', form.item_specifics.map((s, j) => j === i ? { ...s, [field]: val } : s));

  const removeSpecific = (i: number) =>
    set('item_specifics', form.item_specifics.filter((_, j) => j !== i));

  const specificsPayload = () =>
    Object.fromEntries(form.item_specifics.filter(s => s.name.trim() && s.value.trim()).map(s => [s.name.trim(), s.value.trim()]));

  // ── Save draft ───────────────────────────────────────────────────────────

  const handleSaveDraft = async () => {
    if (!canSave || saving) return;
    setSaving(true);
    setSaveError(null);
    try {
      await listingsApi.createDraft({
        title:          form.title,
        condition:      form.condition,
        description:    form.description,
        price:          form.price,
        quantity:       form.quantity,
        sku:            form.sku,
        category:       form.category_suggestion,
        category_id:    form.category_id,
        keywords:       form.keywords,
        item_specifics: specificsPayload(),
        images:         form.images,
        source_url:     url,
        shipping: {
          type:                 form.shipping.type,
          cost:                 form.shipping.cost,
          service:              form.shipping.service,
          processing_days_min:  form.shipping.processing_days_min,
          processing_days_max:  form.shipping.processing_days_max,
          delivery_days_min:    form.shipping.delivery_days_min,
          delivery_days_max:    form.shipping.delivery_days_max,
          origin:               form.shipping_origin,
        },
      });
      router.push('/listings');
    } catch (err: unknown) {
      setSaveError(err instanceof Error ? err.message : 'Entwurf konnte nicht gespeichert werden.');
    } finally {
      setSaving(false);
    }
  };

  // ── Category search ───────────────────────────────────────────────────────

  const handleSearchCategories = async (overrideQuery?: string) => {
    const q = (overrideQuery ?? (form.title || form.category_suggestion)).trim();
    if (!q || searchingCats) return;
    setSearchingCats(true);
    setCatSuggestions([]);
    setCatSearchError(null);
    setShowCatDrop(false);
    try {
      const res = await listingsApi.suggestCategories(q);
      const suggestions = res.data ?? [];
      if (suggestions.length > 0) {
        setCatSuggestions(suggestions);
        setShowCatDrop(true);
      } else {
        setCatSearchError((res.meta as Record<string, string>)?.suggest_error ?? 'Keine Vorschläge gefunden. Kategorie-ID manuell eingeben.');
      }
    } catch (err: unknown) {
      setCatSearchError(err instanceof Error ? err.message : 'Kategoriesuche fehlgeschlagen.');
    } finally {
      setSearchingCats(false);
    }
  };

  const selectCategory = (cat: CategorySuggestion) => {
    set('category_suggestion', cat.name);
    set('category_id', cat.id);
    setShowCatDrop(false);
    setCatSuggestions([]);
  };

  // ── Publish to eBay ───────────────────────────────────────────────────────

  const handlePublish = async () => {
    if (!canPublish || publishing) return;
    setPublishing(true);
    setPublishError(null);
    setPublishedListing(null);
    try {
      const draftRes = await listingsApi.createDraft({
        title: form.title, condition: form.condition, description: form.description,
        price: form.price, quantity: form.quantity, sku: form.sku,
        category: form.category_suggestion, category_id: form.category_id,
        keywords: form.keywords, item_specifics: specificsPayload(), images: form.images, source_url: url,
        shipping: {
          type: form.shipping.type, cost: form.shipping.cost, service: form.shipping.service,
          processing_days_min: form.shipping.processing_days_min, processing_days_max: form.shipping.processing_days_max,
          delivery_days_min: form.shipping.delivery_days_min, delivery_days_max: form.shipping.delivery_days_max,
          origin: form.shipping_origin,
        },
      });
      const published = await listingsApi.publish(draftRes.data.id);
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      setPublishedListing(published.data as any);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Veröffentlichung fehlgeschlagen.';
      setPublishError(msg);
      const missing = extractMissing(msg);
      if (missing.length > 0) {
        setTimeout(() => {
          setForm(f => {
            const existing = new Set(f.item_specifics.map(s => s.name));
            const toAdd = missing.filter(n => !existing.has(n)).map(n => newSpecific(n, ''));
            return toAdd.length > 0 ? { ...f, item_specifics: [...f.item_specifics, ...toAdd] } : f;
          });
        }, 0);
      }
    } finally {
      setPublishing(false);
    }
  };

  const canSave    = form.title.trim().length > 0;
  const canPublish = canSave && form.price.trim().length > 0 && form.category_id.trim().length > 0;

  const titleLen = form.title.length;

  // ─────────────────────────────────────────────────────────────────────────

  return (
    <div className="space-y-6 max-w-3xl">

      {/* Header */}
      <div className="flex items-center gap-3">
        <Link href="/listings" className="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
          <ArrowLeft className="h-5 w-5" />
        </Link>
        <h1 className="text-2xl font-bold text-gray-900">Neues Inserat erstellen</h1>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 bg-gray-100 p-1 rounded-lg w-fit">
        {(['ai', 'manual'] as const).map(t => (
          <button key={t} onClick={() => setTab(t)}
            className={`px-4 py-2 rounded-md text-sm font-medium transition-all flex items-center gap-1.5 ${
              tab === t ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'
            }`}
          >
            {t === 'ai' && <Sparkles className="h-3.5 w-3.5" />}
            {t === 'ai' ? 'KI-Assistent' : 'Manuelle Eingabe'}
          </button>
        ))}
      </div>

      {/* AI URL input */}
      {tab === 'ai' && (
        <div className="bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-100 rounded-xl p-5">
          <p className="text-sm font-semibold text-gray-800 mb-0.5">Produkt-URL</p>
          <p className="text-xs text-gray-500 mb-3">
            Link von AliExpress, DHgate oder einer anderen Dropshipping-Seite einfügen.
            Die KI liest das Produkt und füllt das Formular automatisch auf Deutsch aus.
          </p>
          <div className="flex gap-2">
            <input type="url" value={url}
              onChange={e => setUrl(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && handleAnalyze()}
              placeholder="https://www.aliexpress.com/item/..."
              className="flex-1 px-3 py-2 border border-blue-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-300 bg-white placeholder:text-gray-400"
            />
            <button onClick={handleAnalyze} disabled={!url.trim() || analyzing}
              className="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg text-sm font-medium flex items-center gap-2 transition-colors whitespace-nowrap"
            >
              {analyzing
                ? <><Loader2 className="h-4 w-4 animate-spin" />Analysiert…</>
                : <><Sparkles className="h-4 w-4" />Mit KI analysieren</>
              }
            </button>
          </div>
          {analyzeError && (
            <div className="mt-3 flex items-start gap-2 text-red-600 text-sm bg-red-50 border border-red-200 rounded-lg p-3">
              <AlertCircle className="h-4 w-4 mt-0.5 flex-shrink-0" />{analyzeError}
            </div>
          )}
          {analyzed && !analyzeError && (
            <p className="mt-3 text-sm text-green-700 font-medium">
              ✓ KI-Analyse abgeschlossen — Formular wurde auf Deutsch ausgefüllt. Bitte prüfen und anpassen.
            </p>
          )}
        </div>
      )}

      {/* ── Form ─────────────────────────────────────────────────────────── */}
      <div className="space-y-5">

        {/* Images */}
        <div>
          <p className="text-sm font-medium text-gray-700 mb-2">
            Bilder
            <span className="ml-1.5 text-xs font-normal text-gray-400">
              ({form.images.length} / 12 — hover zum Entfernen)
            </span>
          </p>
          <div className="flex gap-2 flex-wrap">
            {form.images.map((img, i) => (
              <div key={i} className="relative group w-20 h-20">
                <img src={img} alt=""
                  className="w-20 h-20 object-cover rounded-lg border border-gray-200 bg-gray-50"
                  onError={e => { (e.target as HTMLImageElement).parentElement!.style.display = 'none'; }}
                />
                <button onClick={() => removeImage(i)}
                  className="absolute -top-1.5 -right-1.5 bg-red-500 hover:bg-red-600 text-white rounded-full w-5 h-5 hidden group-hover:flex items-center justify-center shadow"
                >
                  <X className="h-3 w-3" />
                </button>
              </div>
            ))}
            {form.images.length < 12 && (
              <div className="w-20 h-20 border-2 border-dashed border-gray-200 rounded-lg flex items-center justify-center">
                <ImageIcon className="h-6 w-6 text-gray-300" />
              </div>
            )}
          </div>
          {/* File upload */}
          {form.images.length < 12 && (
            <div className="mt-2">
              <input ref={fileInputRef} type="file"
                accept="image/*" multiple className="hidden"
                onChange={handleFileUpload}
              />
              <button
                onClick={() => fileInputRef.current?.click()}
                className="flex items-center gap-2 px-3 py-1.5 border border-dashed border-gray-300 hover:border-blue-400 hover:bg-blue-50 text-gray-500 hover:text-blue-600 rounded-lg text-xs font-medium transition-colors"
              >
                <Upload className="h-3.5 w-3.5" />
                Bilder vom Computer hochladen
                <span className="text-gray-400">({12 - form.images.length} verbleibend)</span>
              </button>
              {uploadError && (
                <p className="mt-1 text-xs text-red-500 flex items-center gap-1">
                  <AlertCircle className="h-3 w-3" />{uploadError}
                </p>
              )}
            </div>
          )}
        </div>

        {/* Title */}
        <div>
          <div className="flex justify-between items-center mb-1.5">
            <label className="text-sm font-medium text-gray-700">
              Titel <span className="text-red-400">*</span>
            </label>
            <span className={`text-xs tabular-nums ${
              titleLen > 80 ? 'text-red-500 font-semibold' : titleLen > 70 ? 'text-yellow-500' : 'text-gray-400'
            }`}>{titleLen} / 80</span>
          </div>
          <input type="text" value={form.title}
            onChange={e => set('title', e.target.value)}
            maxLength={80}
            placeholder="z.B. Tierhaarentferner Rolle Hund Katze Wiederverwendbar Fusselroller"
            className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
          />
        </div>

        {/* Condition + Category */}
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">Zustand</label>
            <select value={form.condition} onChange={e => set('condition', e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200 bg-white"
            >
              {CONDITIONS.map(c => <option key={c}>{c}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">
              Kategorie <span className="text-red-400">*</span>
            </label>
            <div className="relative">
              <div className="flex gap-1.5">
                <input type="text" value={form.category_suggestion}
                  onChange={e => { set('category_suggestion', e.target.value); set('category_id', ''); setShowCatDrop(false); }}
                  onKeyDown={e => e.key === 'Enter' && handleSearchCategories()}
                  placeholder="z.B. Haustierbedarf > Hunde"
                  className="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
                />
                <button onClick={() => handleSearchCategories()} disabled={searchingCats}
                  title="eBay-Kategorie suchen"
                  className="px-2.5 py-2 border border-gray-200 rounded-lg hover:bg-gray-50 disabled:opacity-50 transition-colors"
                >
                  {searchingCats ? <Loader2 className="h-4 w-4 animate-spin text-gray-400" /> : <Search className="h-4 w-4 text-gray-400" />}
                </button>
              </div>
              {!form.category_id && !searchingCats && (
                <p className="mt-1 text-xs text-gray-400">
                  Pflichtfeld — klicke auf 🔍 um automatisch zu suchen
                </p>
              )}
              {form.category_id && (
                <p className="mt-1 text-xs text-green-600 flex items-center gap-1">
                  <CheckCircle className="h-3 w-3" /> eBay-ID: {form.category_id}
                </p>
              )}
              {catSearchError && !showCatDrop && (
                <div className="mt-1.5 space-y-1">
                  <p className="text-xs text-amber-600 flex items-start gap-1">
                    <AlertCircle className="h-3 w-3 mt-0.5 shrink-0" />{catSearchError}
                  </p>
                  <div className="flex items-center gap-1.5">
                    <span className="text-xs text-gray-500 shrink-0">Kategorie-ID manuell:</span>
                    <input type="text" value={form.category_id}
                      onChange={e => set('category_id', e.target.value)}
                      placeholder="z.B. 11450"
                      className="w-28 px-2 py-1 border border-gray-200 rounded text-xs focus:outline-none focus:ring-1 focus:ring-blue-200"
                    />
                  </div>
                </div>
              )}
              {showCatDrop && catSuggestions.length > 0 && (
                <div className="absolute z-20 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-52 overflow-y-auto">
                  {catSuggestions.map(cat => (
                    <button key={cat.id} onClick={() => selectCategory(cat)}
                      className="w-full text-left px-3 py-2 text-sm hover:bg-blue-50 flex justify-between items-center border-b border-gray-50 last:border-0"
                    >
                      <span className="font-medium text-gray-800">{cat.name.split(':').join(' › ')}</span>
                      <span className="text-xs text-gray-400 ml-2 shrink-0">ID {cat.id}</span>
                    </button>
                  ))}
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Description */}
        <div>
          <div className="flex items-center justify-between mb-1.5">
            <label className="text-sm font-medium text-gray-700">Beschreibung</label>
            {(form.title || form.description) && (
              <button onClick={handleTranslate} disabled={translating}
                className="flex items-center gap-1 text-xs px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg font-medium transition-colors disabled:opacity-50"
              >
                {translating
                  ? <><Loader2 className="h-3 w-3 animate-spin" />Übersetze…</>
                  : <><Languages className="h-3 w-3" />Vorschau auf Englisch</>
                }
              </button>
            )}
          </div>

          <RichTextEditor
            ref={richEditorRef}
            defaultValue={form.description}
            onChange={v => set('description', v)}
          />

          {translateError && (
            <p className="mt-2 text-xs text-red-500 flex items-center gap-1">
              <AlertCircle className="h-3 w-3" />{translateError}
            </p>
          )}
          {translation && (
            <div className="mt-3 bg-indigo-50 border border-indigo-200 rounded-xl p-4 space-y-2">
              <div className="flex items-center justify-between">
                <p className="text-xs font-semibold text-indigo-700 flex items-center gap-1">
                  <Languages className="h-3.5 w-3.5" />
                  Englische Vorschau (nur zur Ansicht — Inserat wird auf Deutsch veröffentlicht)
                </p>
                <button onClick={() => setTranslation(null)} className="text-indigo-400 hover:text-indigo-600">
                  <X className="h-4 w-4" />
                </button>
              </div>
              <p className="text-sm font-medium text-gray-800">{translation.title}</p>
              <div className="text-sm text-gray-700 prose prose-sm max-w-none"
                dangerouslySetInnerHTML={{ __html: translation.description }}
              />
            </div>
          )}
        </div>

        {/* Price / Quantity / SKU */}
        <div className="grid grid-cols-3 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">Preis (EUR)</label>
            <div className="relative">
              <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm select-none">€</span>
              <input type="number" step="0.01" min="0" value={form.price}
                onChange={e => set('price', e.target.value)}
                placeholder="0.00"
                className="w-full pl-7 pr-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
              />
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">Menge</label>
            <input type="number" min="1" value={form.quantity}
              onChange={e => set('quantity', e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">
              Artikelnr. <span className="font-normal text-gray-400">(optional)</span>
            </label>
            <input type="text" value={form.sku} onChange={e => set('sku', e.target.value)}
              placeholder="SKU-001"
              className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
            />
          </div>
        </div>

        {/* Shipping */}
        <div className="border border-gray-200 rounded-xl p-4 space-y-4">
          <p className="text-sm font-medium text-gray-700">Versand</p>

          {/* Origin */}
          <div>
            <p className="text-xs text-gray-500 mb-1.5">Herkunftsland</p>
            <div className="flex gap-2">
              {ORIGINS.map(o => (
                <button key={o.id} onClick={() => handleOriginChange(o.id)}
                  className={`px-3 py-1.5 rounded-lg text-sm font-medium border transition-all ${
                    form.shipping_origin === o.id
                      ? 'bg-blue-600 border-blue-600 text-white shadow-sm'
                      : 'border-gray-200 text-gray-600 hover:border-blue-300 hover:text-blue-600'
                  }`}
                >{o.label}</button>
              ))}
            </div>
          </div>

          {/* Free/Paid + cost */}
          <div className="grid grid-cols-2 gap-4">
            <div>
              <p className="text-xs text-gray-500 mb-1.5">Versandkosten</p>
              <div className="space-y-2">
                <label className="flex items-center gap-2 cursor-pointer">
                  <input type="checkbox"
                    checked={form.shipping.type === 'free'}
                    onChange={e => handleFreeToggle(e.target.checked)}
                    className="rounded accent-blue-600"
                  />
                  <span className="text-sm text-gray-700">Kostenloser Versand</span>
                </label>
                {form.shipping.type === 'paid' && (
                  <div className="relative">
                    <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">€</span>
                    <input type="number" step="0.01" min="0"
                      value={form.shipping.cost}
                      onChange={e => setShipping('cost', e.target.value)}
                      className="w-full pl-7 pr-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
                    />
                  </div>
                )}
              </div>
            </div>
            <div>
              <p className="text-xs text-gray-500 mb-1.5">Versandservice</p>
              <input type="text"
                value={form.shipping.service}
                onChange={e => setShipping('service', e.target.value)}
                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
              />
            </div>
          </div>

          {/* Processing + Delivery days */}
          <div className="grid grid-cols-2 gap-4">
            <div>
              <p className="text-xs text-gray-500 mb-1.5">Bearbeitungszeit (Werktage)</p>
              <div className="flex items-center gap-2">
                <input type="number" min="0"
                  value={form.shipping.processing_days_min}
                  onChange={e => setShipping('processing_days_min', Number(e.target.value))}
                  className="w-16 px-2 py-2 border border-gray-200 rounded-lg text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-200"
                />
                <span className="text-gray-400 text-sm">–</span>
                <input type="number" min="0"
                  value={form.shipping.processing_days_max}
                  onChange={e => setShipping('processing_days_max', Number(e.target.value))}
                  className="w-16 px-2 py-2 border border-gray-200 rounded-lg text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-200"
                />
                <span className="text-xs text-gray-400">Tage</span>
              </div>
            </div>
            <div>
              <p className="text-xs text-gray-500 mb-1.5">Lieferzeit (Tage)</p>
              <div className="flex items-center gap-2">
                <input type="number" min="0"
                  value={form.shipping.delivery_days_min}
                  onChange={e => setShipping('delivery_days_min', Number(e.target.value))}
                  className="w-16 px-2 py-2 border border-gray-200 rounded-lg text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-200"
                />
                <span className="text-gray-400 text-sm">–</span>
                <input type="number" min="0"
                  value={form.shipping.delivery_days_max}
                  onChange={e => setShipping('delivery_days_max', Number(e.target.value))}
                  className="w-16 px-2 py-2 border border-gray-200 rounded-lg text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-200"
                />
                <span className="text-xs text-gray-400">Tage</span>
              </div>
            </div>
          </div>
        </div>

        {/* Keywords */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">SEO-Schlüsselwörter</label>
          <div className="flex flex-wrap gap-1.5 mb-2">
            {form.keywords.map((kw, i) => (
              <span key={i}
                className="pl-2.5 pr-1.5 py-1 bg-indigo-50 text-indigo-700 rounded-full text-xs font-medium border border-indigo-100 flex items-center gap-1"
              >
                {kw}
                <button onClick={() => set('keywords', form.keywords.filter((_, j) => j !== i))}
                  className="hover:text-red-500 transition-colors"
                >
                  <X className="h-3 w-3" />
                </button>
              </span>
            ))}
          </div>
          <input type="text" placeholder="Schlüsselwort eingeben und Enter drücken…"
            onKeyDown={e => {
              if (e.key === 'Enter' && e.currentTarget.value.trim()) {
                set('keywords', [...form.keywords, e.currentTarget.value.trim()]);
                e.currentTarget.value = '';
              }
            }}
            className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
          />
        </div>

        {/* Item Specifics */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            Artikelmerkmale
            <span className="ml-1.5 text-xs font-normal text-gray-400">(je nach Kategorie von eBay verlangt)</span>
          </label>
          <div className="space-y-2">
            {form.item_specifics.map((s, i) => (
              <div key={s._key} className="flex gap-2 items-center">
                <input type="text" value={s.name}
                  onChange={e => updateSpecific(i, 'name', e.target.value)}
                  placeholder="Merkmal (z.B. Farbe)"
                  className="w-40 px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
                />
                <input type="text" value={s.value}
                  onChange={e => updateSpecific(i, 'value', e.target.value)}
                  placeholder="Wert (z.B. Schwarz)"
                  className="flex-1 px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
                />
                <button onClick={() => removeSpecific(i)} className="p-1.5 text-gray-400 hover:text-red-500 transition-colors">
                  <X className="h-4 w-4" />
                </button>
              </div>
            ))}
            <button onClick={addSpecific}
              className="text-xs text-blue-600 hover:text-blue-800 hover:underline"
            >+ Merkmal hinzufügen</button>
          </div>
        </div>

        {/* Actions */}
        <div className="space-y-3 pt-4 border-t border-gray-100">
          {canSave && form.price.trim() && !form.category_id && !publishedListing && (
            <div className="flex items-start gap-2 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2.5">
              <AlertCircle className="h-4 w-4 flex-shrink-0 mt-0.5 text-amber-500" />
              <span>
                <strong>Kategorie fehlt</strong> — ohne Kategorie kann nicht veröffentlicht werden.
                {' '}Klicke das 🔍 Lupensymbol neben dem Kategorie-Feld, um automatisch die passende eBay-Kategorie zu suchen.
              </span>
            </div>
          )}
          {(saveError || publishError) && (
            <div className="bg-red-50 border border-red-200 rounded-lg px-3 py-2.5 space-y-2">
              <div className="flex items-start gap-2 text-red-600 text-sm">
                <AlertCircle className="h-4 w-4 flex-shrink-0 mt-0.5" />
                <span>{saveError ?? publishError}</span>
              </div>
              {publishError?.includes('is missing') && (
                <div className="flex flex-col gap-1">
                  <button
                    onClick={handleSolveWithAI}
                    disabled={solvingAI}
                    className="inline-flex items-center gap-1.5 text-xs font-medium bg-purple-600 hover:bg-purple-700 disabled:opacity-60 text-white px-2.5 py-1.5 rounded-md transition-colors w-fit"
                  >
                    {solvingAI
                      ? <><Loader2 className="h-3 w-3 animate-spin" /> KI löst…</>
                      : <><Sparkles className="h-3 w-3" /> Mit KI lösen</>}
                  </button>
                  {aiSolveError && (
                    <p className="text-xs text-red-500 flex items-center gap-1">
                      <AlertCircle className="h-3 w-3 flex-shrink-0" />{aiSolveError}
                    </p>
                  )}
                </div>
              )}
            </div>
          )}
          {publishedListing && (
            <div className="bg-green-50 border border-green-200 rounded-xl p-4 space-y-2">
              <p className="text-green-700 font-semibold text-sm flex items-center gap-2">
                <CheckCircle className="h-4 w-4" /> Inserat wurde erfolgreich auf eBay veröffentlicht!
              </p>
              <div className="flex items-center gap-3 flex-wrap">
                {(publishedListing as { listing_url?: string }).listing_url && (
                  <a href={(publishedListing as { listing_url?: string }).listing_url} target="_blank" rel="noopener noreferrer"
                    className="inline-flex items-center gap-1.5 text-sm text-blue-600 hover:underline"
                  >
                    <ExternalLink className="h-3.5 w-3.5" /> Auf eBay ansehen
                  </a>
                )}
                <button onClick={handleReset}
                  className="inline-flex items-center gap-1.5 text-sm font-medium bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg transition-colors"
                >
                  + Neues Inserat erstellen
                </button>
              </div>
            </div>
          )}
          <div className="flex items-center gap-3">
            <button
              disabled={!canSave || saving || !!publishedListing}
              onClick={handleSaveDraft}
              className="px-4 py-2 border border-gray-200 rounded-lg text-sm font-medium transition-colors flex items-center gap-2
                disabled:text-gray-300 disabled:cursor-not-allowed
                enabled:text-gray-700 enabled:hover:bg-gray-50 enabled:cursor-pointer"
            >
              {saving ? <><Loader2 className="h-4 w-4 animate-spin" />Speichere…</> : 'Entwurf speichern'}
            </button>
            <button
              disabled={!canPublish || publishing || !!publishedListing}
              onClick={handlePublish}
              className="px-5 py-2 rounded-lg text-sm font-medium text-white transition-colors flex items-center gap-2
                disabled:bg-blue-300 disabled:cursor-not-allowed
                enabled:bg-blue-600 enabled:hover:bg-blue-700 enabled:cursor-pointer"
            >
              {publishing ? <><Loader2 className="h-4 w-4 animate-spin" />Wird veröffentlicht…</> : 'Auf eBay veröffentlichen'}
            </button>
            {!canPublish && !publishedListing && (
              <span className="text-xs text-gray-400">
                {!canSave ? 'Titel erforderlich' : !form.price.trim() ? 'Preis erforderlich' : ''}
              </span>
            )}
          </div>
        </div>

      </div>
    </div>
  );
}
