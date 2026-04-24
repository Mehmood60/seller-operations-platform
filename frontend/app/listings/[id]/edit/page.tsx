'use client';

import { useState, useRef, useEffect } from 'react';
import Link from 'next/link';
import { useParams, useRouter } from 'next/navigation';
import {
  ArrowLeft, Sparkles, Loader2, AlertCircle, X,
  Languages, Upload, ImageIcon, Trash2, Search, CheckCircle, ExternalLink, Link2,
  Activity, RefreshCw, ChevronDown, ChevronUp,
} from 'lucide-react';
import { ai as aiApi, listings as listingsApi } from '@/lib/api';
import { RichTextEditor } from '@/components/RichTextEditor';
import type { AiShipping, HealthScore, ShippingOrigin } from '@/types';

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

interface Translation { title: string; description: string }
interface CategorySuggestion { id: string; name: string; percent: number }
interface ItemSpecific { _key: string; name: string; value: string }

const newSpecific = (name = '', value = ''): ItemSpecific =>
  ({ _key: Math.random().toString(36).slice(2), name, value });

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

export default function EditListingPage() {
  const params  = useParams();
  const id      = params.id as string;
  const router  = useRouter();

  // draft/listing loading
  const [loadingDraft, setLoadingDraft]   = useState(true);
  const [loadError, setLoadError]         = useState<string | null>(null);
  const [listingStatus, setListingStatus] = useState<string>('DRAFT');

  // revise (for ACTIVE listings)
  const [revising, setRevising]           = useState(false);
  const [reviseError, setReviseError]     = useState<string | null>(null);
  const [revisedSuccess, setRevisedSuccess] = useState(false);

  // form
  const [url, setUrl]                   = useState('');
  const [form, setForm]                 = useState<FormData>(EMPTY);
  const [editorKey, setEditorKey]       = useState(0);

  // AI re-analyze
  const [analyzing, setAnalyzing]       = useState(false);
  const [analyzeError, setAnalyzeError] = useState<string | null>(null);
  const [analyzed, setAnalyzed]         = useState(false);
  const [savingUrl, setSavingUrl]       = useState(false);
  const [urlSaved, setUrlSaved]         = useState(false);

  // translate
  const [translating, setTranslating]   = useState(false);
  const [translation, setTranslation]   = useState<Translation | null>(null);
  const [translateError, setTranslateError] = useState<string | null>(null);

  // save / delete
  const [saving, setSaving]             = useState(false);
  const [saveError, setSaveError]       = useState<string | null>(null);
  const [deleting, setDeleting]         = useState(false);

  // publish
  const [publishing, setPublishing]             = useState(false);
  const [publishError, setPublishError]         = useState<string | null>(null);
  const [publishedListing, setPublishedListing] = useState<{ listing_url?: string } | null>(null);
  const [solvingAI, setSolvingAI]               = useState(false);
  const [aiSolveError, setAiSolveError]         = useState<string | null>(null);
  const [fixingHealthIssue, setFixingHealthIssue] = useState<string | null>(null);

  // health score
  const [health, setHealth]                     = useState<HealthScore | null>(null);
  const [healthLoading, setHealthLoading]       = useState(false);
  const [healthExpanded, setHealthExpanded]     = useState(true);

  // category search
  const [searchingCats, setSearchingCats]       = useState(false);
  const [catSuggestions, setCatSuggestions]     = useState<CategorySuggestion[]>([]);
  const [showCatDrop, setShowCatDrop]           = useState(false);
  const [catSearchError, setCatSearchError]     = useState<string | null>(null);

  // image upload
  const [uploadError, setUploadError]   = useState<string | null>(null);
  const fileInputRef                    = useRef<HTMLInputElement>(null);


  // ── Load draft on mount ───────────────────────────────────────────────────

  useEffect(() => {
    if (!id) return;
    listingsApi.get(id)
      .then(res => {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const d = res.data as any;
        const sh = d.shipping ?? {};
        setListingStatus(d.status ?? 'DRAFT');
        setUrl(d.source_url ?? '');
        setForm({
          title:               d.title               ?? '',
          condition:           d.condition            ?? 'Neu',
          description:         d.description          ?? '',
          price:               d.price?.value         ?? '',
          quantity:            String(d.quantity?.available ?? 1),
          sku:                 d.sku                  ?? '',
          shipping_origin:     sh.origin              ?? 'DE',
          category_id:         d.category?.ebay_category_id ?? '',
          shipping: {
            type:                sh.type                ?? 'free',
            cost:                sh.cost                ?? '0.00',
            service:             sh.service             ?? 'Standardversand (eBay)',
            processing_days_min: sh.processing_days_min ?? 1,
            processing_days_max: sh.processing_days_max ?? 2,
            delivery_days_min:   sh.delivery_days_min   ?? 1,
            delivery_days_max:   sh.delivery_days_max   ?? 2,
          },
          category_suggestion: d.category?.name       ?? '',
          keywords:            d.keywords              ?? [],
          item_specifics:      Object.keys(d.item_specifics ?? {}).length > 0
            ? Object.entries(d.item_specifics as Record<string, string>).map(([name, value]) => newSpecific(name, value))
            : [newSpecific('Marke', 'Ohne Markenzeichen')],
          images:              d.images                ?? [],
        });
      })
      .then(() => {
        listingsApi.health(id).then(r => setHealth(r.data)).catch(() => {});
      })
      .catch(err => setLoadError(err instanceof Error ? err.message : 'Entwurf nicht gefunden.'))
      .finally(() => setLoadingDraft(false));
  }, [id]);

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

  // ── AI re-analyze ─────────────────────────────────────────────────────────

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
      setForm({
        title:               s.title               ?? '',
        condition:           s.condition            ?? 'Neu',
        description:         s.description          ?? '',
        price:               s.price?.value         ?? '',
        quantity:            '1',
        sku:                 '',
        shipping_origin:     s.shipping_origin      ?? 'UNKNOWN',
        shipping:            SHIPPING_DE,
        category_suggestion: s.category_suggestion  ?? '',
        category_id:         '',
        keywords:            s.keywords             ?? [],
        item_specifics:      s.item_specifics && Object.keys(s.item_specifics).length > 0
          ? Object.entries(s.item_specifics).map(([name, value]) => newSpecific(name, value as string))
          : [newSpecific('Marke', 'Ohne Markenzeichen')],
        images:              raw.images             ?? [],
      });
      setEditorKey(k => k + 1);
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

  // ── Re-fetch health score ─────────────────────────────────────────────────

  const fetchHealth = async () => {
    setHealthLoading(true);
    try {
      const r = await listingsApi.health(id);
      setHealth(r.data);
      setHealthExpanded(true);
    } catch { /* silent */ }
    finally { setHealthLoading(false); }
  };

  // ── AI fix for item specifics (fills, saves, revises if ACTIVE) ───────────

  const handleAiFixSpecifics = async () => {
    if (fixingHealthIssue || solvingAI) return;
    setFixingHealthIssue('specifics');
    setSolvingAI(true);
    setAiSolveError(null);
    try {
      const currentSpecifics: Record<string, string> = {};
      form.item_specifics.forEach(s => { if (s.name.trim()) currentSpecifics[s.name] = s.value; });

      const emptyFields = form.item_specifics
        .filter(s => s.name.trim() && !s.value.trim()).map(s => s.name);
      const missingFields = emptyFields.length > 0
        ? emptyFields
        : ['Marke', 'Farbe', 'Material', 'Produktart'];

      const res = await aiApi.suggestSpecifics({
        title: form.title,
        description: form.description,
        item_specifics: currentSpecifics,
        missing_fields: missingFields,
      });
      const suggestions = res.data as Record<string, string>;

      const updatedSpecs = form.item_specifics.map(s =>
        suggestions[s.name] !== undefined ? { ...s, value: suggestions[s.name] } : s
      );
      const existingNames = new Set(updatedSpecs.map(s => s.name));
      const toAdd = Object.entries(suggestions)
        .filter(([name]) => !existingNames.has(name))
        .map(([name, value]) => newSpecific(name, value));
      const newSpecifics = [...updatedSpecs, ...toAdd];

      const payload = Object.fromEntries(
        newSpecifics.filter(s => s.name.trim() && s.value.trim()).map(s => [s.name.trim(), s.value.trim()])
      );

      setForm(f => ({ ...f, item_specifics: newSpecifics }));
      await listingsApi.updateDraft(id, { item_specifics: payload });

      if (listingStatus === 'ACTIVE') {
        await listingsApi.revise(id, {
          title: form.title, condition: form.condition, description: form.description,
          price: form.price, quantity: form.quantity, sku: form.sku,
          category: form.category_suggestion, category_id: form.category_id,
          keywords: form.keywords, item_specifics: payload, images: form.images,
          shipping: {
            type: form.shipping.type, cost: form.shipping.cost, service: form.shipping.service,
            processing_days_min: form.shipping.processing_days_min,
            processing_days_max: form.shipping.processing_days_max,
            delivery_days_min: form.shipping.delivery_days_min,
            delivery_days_max: form.shipping.delivery_days_max,
            origin: form.shipping_origin,
          },
        });
      }
      await fetchHealth();
    } catch (err: unknown) {
      setAiSolveError(err instanceof Error ? err.message : 'AI could not fill the missing fields.');
    } finally {
      setSolvingAI(false);
      setFixingHealthIssue(null);
    }
  };

  const handleAiFixTitle = async () => {
    if (fixingHealthIssue) return;
    setFixingHealthIssue('title');
    setAiSolveError(null);
    try {
      const res = await aiApi.improveListing({ aspect: 'title', title: form.title, description: form.description });
      const improved = res.data.title ?? '';
      if (improved) {
        setForm(f => ({ ...f, title: improved }));
        await listingsApi.updateDraft(id, { title: improved });
        await fetchHealth();
      }
    } catch (err: unknown) {
      setAiSolveError(err instanceof Error ? err.message : 'AI could not improve the title.');
    } finally {
      setFixingHealthIssue(null);
    }
  };

  const handleAiFixDescription = async () => {
    if (fixingHealthIssue) return;
    setFixingHealthIssue('description');
    setAiSolveError(null);
    try {
      const res = await aiApi.improveListing({ aspect: 'description', title: form.title, description: form.description });
      const improved = res.data.description ?? '';
      if (improved) {
        setForm(f => ({ ...f, description: improved }));
        setEditorKey(k => k + 1);
        await listingsApi.updateDraft(id, { description: improved });
        await fetchHealth();
      }
    } catch (err: unknown) {
      setAiSolveError(err instanceof Error ? err.message : 'AI could not improve the description.');
    } finally {
      setFixingHealthIssue(null);
    }
  };

  const scrollTo = (sectionId: string) =>
    document.getElementById(sectionId)?.scrollIntoView({ behavior: 'smooth', block: 'center' });

  // ── Save source URL only (without re-analyzing) ──────────────────────────

  const handleSaveUrl = async () => {
    if (!url.trim() || savingUrl) return;
    setSavingUrl(true);
    setUrlSaved(false);
    try {
      await listingsApi.updateDraft(id, { source_url: url.trim() });
      setUrlSaved(true);
      setTimeout(() => setUrlSaved(false), 3000);
    } catch {
      // ignore — user can retry
    } finally {
      setSavingUrl(false);
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

  // ── Shipping ──────────────────────────────────────────────────────────────

  const handleOriginChange = (origin: ShippingOrigin) => {
    const preset = origin === 'DE' ? SHIPPING_DE : origin === 'CN' ? SHIPPING_CN : form.shipping;
    setForm(f => ({ ...f, shipping_origin: origin, shipping: preset }));
  };

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

  // ── Image upload ─────────────────────────────────────────────────────────

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

  // ── Save draft ────────────────────────────────────────────────────────────

  const handleSaveDraft = async () => {
    if (!canSave || saving) return;
    setSaving(true);
    setSaveError(null);
    try {
      await listingsApi.updateDraft(id, {
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
          type:                form.shipping.type,
          cost:                form.shipping.cost,
          service:             form.shipping.service,
          processing_days_min: form.shipping.processing_days_min,
          processing_days_max: form.shipping.processing_days_max,
          delivery_days_min:   form.shipping.delivery_days_min,
          delivery_days_max:   form.shipping.delivery_days_max,
          origin:              form.shipping_origin,
        },
      });
      router.push('/listings');
    } catch (err: unknown) {
      setSaveError(err instanceof Error ? err.message : 'Entwurf konnte nicht gespeichert werden.');
    } finally {
      setSaving(false);
    }
  };

  // ── Delete draft ──────────────────────────────────────────────────────────

  const handleDelete = async () => {
    if (!confirm('Delete this draft? This action cannot be undone.')) return;
    setDeleting(true);
    try {
      await listingsApi.deleteDraft(id);
      router.push('/listings');
    } catch (err: unknown) {
      setSaveError(err instanceof Error ? err.message : 'Entwurf konnte nicht gelöscht werden.');
      setDeleting(false);
    }
  };

  // ── Revise active listing on eBay ────────────────────────────────────────

  const handleRevise = async () => {
    if (!canSave || revising) return;
    setRevising(true);
    setReviseError(null);
    setRevisedSuccess(false);
    try {
      await listingsApi.updateDraft(id, {
        title: form.title, condition: form.condition, description: form.description,
        price: form.price, quantity: form.quantity, sku: form.sku,
        category: form.category_suggestion, category_id: form.category_id,
        keywords: form.keywords, item_specifics: specificsPayload(), images: form.images,
        source_url: url,
        shipping: {
          type: form.shipping.type, cost: form.shipping.cost, service: form.shipping.service,
          processing_days_min: form.shipping.processing_days_min, processing_days_max: form.shipping.processing_days_max,
          delivery_days_min: form.shipping.delivery_days_min, delivery_days_max: form.shipping.delivery_days_max,
          origin: form.shipping_origin,
        },
      });
      await listingsApi.revise(id, {
        title: form.title, condition: form.condition, description: form.description,
        price: form.price, quantity: form.quantity, sku: form.sku,
        category: form.category_suggestion, category_id: form.category_id,
        keywords: form.keywords, item_specifics: specificsPayload(), images: form.images,
        shipping: {
          type: form.shipping.type, cost: form.shipping.cost, service: form.shipping.service,
          processing_days_min: form.shipping.processing_days_min, processing_days_max: form.shipping.processing_days_max,
          delivery_days_min: form.shipping.delivery_days_min, delivery_days_max: form.shipping.delivery_days_max,
          origin: form.shipping_origin,
        },
      });
      setRevisedSuccess(true);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Aktualisierung fehlgeschlagen.';
      setReviseError(msg);
      const missing = [...msg.matchAll(/The item specific (.+?) is missing/g)].map(m => m[1]);
      if (missing.length > 0) {
        setForm(f => {
          const existing = new Set(f.item_specifics.map(s => s.name));
          const toAdd = missing.filter(n => !existing.has(n)).map(n => newSpecific(n, ''));
          return toAdd.length > 0 ? { ...f, item_specifics: [...f.item_specifics, ...toAdd] } : f;
        });
      }
    } finally {
      setRevising(false);
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
      await listingsApi.updateDraft(id, {
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
      const published = await listingsApi.publish(id);
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
  const titleLen   = form.title.length;

  // ── Loading / error states ────────────────────────────────────────────────

  if (loadingDraft) {
    return (
      <div className="flex items-center justify-center py-24 text-gray-400">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-[#0f3460] border-t-transparent" />
      </div>
    );
  }

  if (loadError) {
    return (
      <div className="space-y-4">
        <Link href="/listings" className="inline-flex items-center gap-2 text-sm text-gray-400 hover:text-gray-600">
          <ArrowLeft className="h-4 w-4" /> Zurück zu Inseraten
        </Link>
        <p className="text-red-500">{loadError}</p>
      </div>
    );
  }

  // ─────────────────────────────────────────────────────────────────────────

  return (
    <div className="space-y-6 max-w-3xl">

      {/* Header */}
      <div className="flex items-center gap-3">
        <Link href="/listings" className="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
          <ArrowLeft className="h-5 w-5" />
        </Link>
        <h1 className="text-2xl font-bold text-gray-900 flex-1">
          {listingStatus === 'ACTIVE' ? 'Edit Listing' : 'Edit Draft'}
        </h1>
        {listingStatus === 'DRAFT' && (
          <button
            onClick={handleDelete}
            disabled={deleting}
            className="flex items-center gap-1.5 px-3 py-1.5 text-sm text-red-500 hover:text-red-700 hover:bg-red-50 border border-red-200 rounded-lg transition-colors disabled:opacity-50"
          >
            {deleting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Trash2 className="h-4 w-4" />}
            Delete
          </button>
        )}
      </div>

      {/* CJ Dropshipping detection banner */}
      {form.sku.toUpperCase().startsWith('CJ') && !url && (
        <div className="flex items-start gap-3 bg-orange-50 border border-orange-200 rounded-xl p-4">
          <span className="text-xl leading-none flex-shrink-0">📦</span>
          <div className="space-y-2 text-sm flex-1">
            <p className="font-semibold text-orange-900">CJDropshipping Product Detected</p>
            <p className="text-orange-700">
              SKU <strong>{form.sku}</strong> — paste the CJ product URL below
              so price monitoring works in the Monitor tab.
            </p>
            <ol className="text-xs text-orange-700 space-y-1 list-decimal list-inside">
              <li>
                Click{' '}
                <a
                  href={`https://www.google.com/search?q=cjdropshipping.com+${encodeURIComponent(form.sku)}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-0.5 underline font-medium hover:text-orange-900"
                >
                  here to search for the product on Google <ExternalLink className="h-3 w-3" />
                </a>
              </li>
              <li>Open the CJDropshipping link from the search results</li>
              <li>Copy the URL, paste it in the URL field below → click <strong>Save URL</strong></li>
            </ol>
          </div>
        </div>
      )}

      {/* AI re-analyze box */}
      <div id="section-source_url" className="bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-100 rounded-xl p-5">
        <p className="text-sm font-semibold text-gray-800 mb-0.5">Product URL</p>
        <p className="text-xs text-gray-500 mb-3">
          Save URL for price monitoring — or re-analyze to fill all fields from the product page.
        </p>
        <div className="flex gap-2">
          <input type="url" value={url}
            onChange={e => { setUrl(e.target.value); setUrlSaved(false); }}
            onKeyDown={e => e.key === 'Enter' && handleAnalyze()}
            placeholder="https://www.aliexpress.com/item/..."
            className="flex-1 px-3 py-2 border border-blue-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-300 bg-white placeholder:text-gray-400"
          />
          <button onClick={handleSaveUrl} disabled={!url.trim() || savingUrl || analyzing}
            className="px-3 py-2 border border-blue-300 bg-white hover:bg-blue-50 disabled:opacity-50 disabled:cursor-not-allowed text-blue-700 rounded-lg text-sm font-medium flex items-center gap-1.5 transition-colors whitespace-nowrap"
          >
            {savingUrl
              ? <Loader2 className="h-4 w-4 animate-spin" />
              : urlSaved
                ? <><CheckCircle className="h-4 w-4 text-green-600" /><span className="text-green-700">Gespeichert</span></>
                : <><Link2 className="h-4 w-4" />URL speichern</>
            }
          </button>
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
            ✓ Erneute Analyse abgeschlossen — Felder wurden aktualisiert.
          </p>
        )}
      </div>

      {/* Health Score Panel */}
      {health && (
        <div className={`border rounded-xl overflow-hidden ${
          health.grade === 'A' ? 'border-green-200 bg-green-50' :
          health.grade === 'B' ? 'border-blue-200 bg-blue-50' :
          health.grade === 'C' ? 'border-yellow-200 bg-yellow-50' :
          'border-red-200 bg-red-50'
        }`}>
          {/* Header row */}
          <div className="flex items-center justify-between px-4 py-3">
            <div className="flex items-center gap-3">
              <Activity className={`h-4 w-4 ${
                health.grade === 'A' ? 'text-green-600' :
                health.grade === 'B' ? 'text-blue-600' :
                health.grade === 'C' ? 'text-yellow-600' : 'text-red-600'
              }`} />
              <span className="text-sm font-semibold text-gray-800">Listing Quality Score</span>
              <div className="flex items-center gap-2">
                <span className={`text-2xl font-bold tabular-nums ${
                  health.grade === 'A' ? 'text-green-700' :
                  health.grade === 'B' ? 'text-blue-700' :
                  health.grade === 'C' ? 'text-yellow-700' : 'text-red-700'
                }`}>{health.score}</span>
                <span className="text-xs text-gray-400 font-normal">/100</span>
                <span className={`ml-1 px-2 py-0.5 rounded-full text-sm font-bold ${
                  health.grade === 'A' ? 'bg-green-200 text-green-800' :
                  health.grade === 'B' ? 'bg-blue-200 text-blue-800' :
                  health.grade === 'C' ? 'bg-yellow-200 text-yellow-800' :
                  'bg-red-200 text-red-800'
                }`}>{health.grade}</span>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <button onClick={fetchHealth} disabled={healthLoading} title="Recalculate"
                className="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-white/60 transition-colors disabled:opacity-40"
              >
                <RefreshCw className={`h-3.5 w-3.5 ${healthLoading ? 'animate-spin' : ''}`} />
              </button>
              <button onClick={() => setHealthExpanded(e => !e)}
                className="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-white/60 transition-colors"
              >
                {healthExpanded ? <ChevronUp className="h-3.5 w-3.5" /> : <ChevronDown className="h-3.5 w-3.5" />}
              </button>
            </div>
          </div>

          {/* Progress bar */}
          <div className="px-4 pb-1">
            <div className="h-1.5 bg-white/60 rounded-full overflow-hidden">
              <div className={`h-full rounded-full transition-all duration-500 ${
                health.grade === 'A' ? 'bg-green-500' :
                health.grade === 'B' ? 'bg-blue-500' :
                health.grade === 'C' ? 'bg-yellow-500' : 'bg-red-500'
              }`} style={{ width: `${health.score}%` }} />
            </div>
          </div>

          {/* Expanded content */}
          {healthExpanded && (
            <div className="px-4 py-3 space-y-3 border-t border-white/40">

              {/* Dimension bars */}
              <div className="grid grid-cols-6 gap-1.5 text-center">
                {(Object.entries(health.dims) as [string, number][]).map(([dim, pts]) => {
                  const max = { title: 20, images: 20, specifics: 20, description: 15, category: 15, source_url: 10 }[dim] ?? 20;
                  const pct = Math.round((pts / max) * 100);
                  const labels: Record<string, string> = {
                    title: 'Title', images: 'Images', specifics: 'Specifics',
                    description: 'Desc.', category: 'Category', source_url: 'Source',
                  };
                  return (
                    <div key={dim} className="flex flex-col items-center gap-1">
                      <div className="relative h-12 w-6 bg-white/60 rounded overflow-hidden">
                        <div className={`absolute bottom-0 w-full rounded transition-all ${
                          pct === 100 ? 'bg-green-500' : pct >= 60 ? 'bg-blue-500' : pct > 0 ? 'bg-yellow-500' : 'bg-red-400'
                        }`} style={{ height: `${pct}%` }} />
                      </div>
                      <span className="text-xs text-gray-500 leading-tight">{labels[dim]}</span>
                      <span className="text-xs font-semibold text-gray-700">{pts}/{max}</span>
                    </div>
                  );
                })}
              </div>

              {/* Issue list */}
              {health.issues.length > 0 ? (
                <ul className="space-y-1.5">
                  {health.issues.map((issue, i) => (
                    <li key={i} className={`flex items-start justify-between gap-2 text-xs rounded-lg px-2.5 py-2 ${
                      issue.priority === 'high'   ? 'bg-red-100/70 text-red-800' :
                      issue.priority === 'medium' ? 'bg-yellow-100/70 text-yellow-800' :
                      'bg-gray-100/70 text-gray-700'
                    }`}>
                      <div className="flex items-start gap-2 min-w-0">
                        <span className="mt-0.5 shrink-0">
                          {issue.priority === 'high' ? '🔴' : issue.priority === 'medium' ? '🟡' : '🔵'}
                        </span>
                        <span>{issue.message}</span>
                      </div>
                      {/* Action button */}
                      {issue.action === 'specifics' ? (
                        <button
                          onClick={handleAiFixSpecifics}
                          disabled={!!fixingHealthIssue || solvingAI}
                          className="shrink-0 flex items-center gap-1 px-2 py-1 bg-purple-600 hover:bg-purple-700 disabled:opacity-60 text-white rounded text-xs font-medium transition-colors"
                        >
                          {fixingHealthIssue === 'specifics' || solvingAI
                            ? <><Loader2 className="h-3 w-3 animate-spin" />Fixing…</>
                            : <><Sparkles className="h-3 w-3" />Fix with AI</>}
                        </button>
                      ) : issue.action === 'title' ? (
                        <button
                          onClick={handleAiFixTitle}
                          disabled={!!fixingHealthIssue}
                          className="shrink-0 flex items-center gap-1 px-2 py-1 bg-purple-600 hover:bg-purple-700 disabled:opacity-60 text-white rounded text-xs font-medium transition-colors"
                        >
                          {fixingHealthIssue === 'title'
                            ? <><Loader2 className="h-3 w-3 animate-spin" />Fixing…</>
                            : <><Sparkles className="h-3 w-3" />Fix with AI</>}
                        </button>
                      ) : issue.action === 'description' ? (
                        <button
                          onClick={handleAiFixDescription}
                          disabled={!!fixingHealthIssue}
                          className="shrink-0 flex items-center gap-1 px-2 py-1 bg-purple-600 hover:bg-purple-700 disabled:opacity-60 text-white rounded text-xs font-medium transition-colors"
                        >
                          {fixingHealthIssue === 'description'
                            ? <><Loader2 className="h-3 w-3 animate-spin" />Fixing…</>
                            : <><Sparkles className="h-3 w-3" />Fix with AI</>}
                        </button>
                      ) : (
                        <button
                          onClick={() => scrollTo(`section-${issue.action}`)}
                          className="shrink-0 px-2 py-1 bg-white/70 hover:bg-white border border-current/20 rounded text-xs font-medium transition-colors"
                        >
                          Go to ↓
                        </button>
                      )}
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-xs text-green-700 font-medium text-center py-1">
                  ✓ Excellent! This listing has no quality issues.
                </p>
              )}

              {aiSolveError && (
                <p className="text-xs text-red-600 flex items-center gap-1">
                  <AlertCircle className="h-3 w-3 shrink-0" />{aiSolveError}
                </p>
              )}
            </div>
          )}
        </div>
      )}

      {/* ── Form ─────────────────────────────────────────────────────────── */}
      <div className="space-y-5">

        {/* Images */}
        <div id="section-images">
          <p className="text-sm font-medium text-gray-700 mb-2">
            Images
            <span className="ml-1.5 text-xs font-normal text-gray-400">
              ({form.images.length} / 12 — hover to remove)
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
                Upload images from computer
                <span className="text-gray-400">({12 - form.images.length} remaining)</span>
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
        <div id="section-title">
          <div className="flex justify-between items-center mb-1.5">
            <label className="text-sm font-medium text-gray-700">
              Title <span className="text-red-400">*</span>
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
            <label className="block text-sm font-medium text-gray-700 mb-1.5">Condition</label>
            <select value={form.condition} onChange={e => set('condition', e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200 bg-white"
            >
              {CONDITIONS.map(c => <option key={c}>{c}</option>)}
            </select>
          </div>
          <div id="section-category">
            <label className="block text-sm font-medium text-gray-700 mb-1.5">
              Category <span className="text-red-400">*</span>
            </label>
            <div className="relative">
              <div className="flex gap-1.5">
                <input type="text" value={form.category_suggestion}
                  onChange={e => { set('category_suggestion', e.target.value); set('category_id', ''); setShowCatDrop(false); }}
                  onKeyDown={e => e.key === 'Enter' && handleSearchCategories()}
                  placeholder="e.g. Pet Supplies > Dogs"
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
                  Required — click 🔍 to search automatically
                </p>
              )}
              {form.category_id && (
                <p className="mt-1 text-xs text-green-600 flex items-center gap-1">
                  <CheckCircle className="h-3 w-3" /> eBay ID: {form.category_id}
                </p>
              )}
              {catSearchError && !showCatDrop && (
                <div className="mt-1.5 space-y-1">
                  <p className="text-xs text-amber-600 flex items-start gap-1">
                    <AlertCircle className="h-3 w-3 mt-0.5 shrink-0" />{catSearchError}
                  </p>
                  <div className="flex items-center gap-1.5">
                    <span className="text-xs text-gray-500 shrink-0">Enter category ID manually:</span>
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
        <div id="section-description">
          <div className="flex items-center justify-between mb-1.5">
            <label className="text-sm font-medium text-gray-700">Description</label>
            {(form.title || form.description) && (
              <button onClick={handleTranslate} disabled={translating}
                className="flex items-center gap-1 text-xs px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg font-medium transition-colors disabled:opacity-50"
              >
                {translating
                  ? <><Loader2 className="h-3 w-3 animate-spin" />Translating…</>
                  : <><Languages className="h-3 w-3" />Preview in English</>
                }
              </button>
            )}
          </div>

          <RichTextEditor
            key={editorKey}
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
                  English preview (view only — listing will be published in German)
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
            <label className="block text-sm font-medium text-gray-700 mb-1.5">Price (EUR)</label>
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
            <label className="block text-sm font-medium text-gray-700 mb-1.5">Quantity</label>
            <input type="number" min="1" value={form.quantity}
              onChange={e => set('quantity', e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">
              SKU <span className="font-normal text-gray-400">(optional)</span>
            </label>
            <input type="text" value={form.sku} onChange={e => set('sku', e.target.value)}
              placeholder="SKU-001"
              className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
            />
          </div>
        </div>

        {/* Shipping */}
        <div className="border border-gray-200 rounded-xl p-4 space-y-4">
          <p className="text-sm font-medium text-gray-700">Shipping</p>

          <div>
            <p className="text-xs text-gray-500 mb-1.5">Ships from</p>
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

          <div className="grid grid-cols-2 gap-4">
            <div>
              <p className="text-xs text-gray-500 mb-1.5">Shipping cost</p>
              <div className="space-y-2">
                <label className="flex items-center gap-2 cursor-pointer">
                  <input type="checkbox"
                    checked={form.shipping.type === 'free'}
                    onChange={e => handleFreeToggle(e.target.checked)}
                    className="rounded accent-blue-600"
                  />
                  <span className="text-sm text-gray-700">Free shipping</span>
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
              <p className="text-xs text-gray-500 mb-1.5">Shipping service</p>
              <input type="text"
                value={form.shipping.service}
                onChange={e => setShipping('service', e.target.value)}
                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <p className="text-xs text-gray-500 mb-1.5">Processing time (business days)</p>
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
              <p className="text-xs text-gray-500 mb-1.5">Delivery time (days)</p>
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
                <span className="text-xs text-gray-400">days</span>
              </div>
            </div>
          </div>
        </div>

        {/* Keywords */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">SEO Keywords</label>
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
          <input type="text" placeholder="Type keyword and press Enter…"
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
        <div id="section-specifics">
          <label className="block text-sm font-medium text-gray-700 mb-1">
            Item Specifics
            <span className="ml-1.5 text-xs font-normal text-gray-400">(required by eBay depending on category)</span>
          </label>
          <div className="space-y-2">
            {form.item_specifics.map((s, i) => (
              <div key={s._key} className="flex gap-2 items-center">
                <input type="text" value={s.name}
                  onChange={e => updateSpecific(i, 'name', e.target.value)}
                  placeholder="Attribute (e.g. Farbe)"
                  className="w-40 px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
                />
                <input type="text" value={s.value}
                  onChange={e => updateSpecific(i, 'value', e.target.value)}
                  placeholder="Value (e.g. Schwarz)"
                  className="flex-1 px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
                />
                <button onClick={() => removeSpecific(i)} className="p-1.5 text-gray-400 hover:text-red-500 transition-colors">
                  <X className="h-4 w-4" />
                </button>
              </div>
            ))}
            <button onClick={addSpecific}
              className="text-xs text-blue-600 hover:text-blue-800 hover:underline"
            >+ Add attribute</button>
          </div>
        </div>

        {/* Actions */}
        <div className="space-y-3 pt-4 border-t border-gray-100">

          {/* ACTIVE listing — revise on eBay */}
          {listingStatus === 'ACTIVE' && (
            <>
              {reviseError && (
                <div className="flex items-start gap-2 text-red-600 text-sm bg-red-50 border border-red-200 rounded-lg px-3 py-2">
                  <AlertCircle className="h-4 w-4 flex-shrink-0 mt-0.5" />{reviseError}
                </div>
              )}
              {revisedSuccess && (
                <div className="flex items-center gap-2 text-green-700 text-sm bg-green-50 border border-green-200 rounded-lg px-3 py-2">
                  <CheckCircle className="h-4 w-4 flex-shrink-0" /> Listing updated successfully on eBay!
                </div>
              )}
              <div className="flex items-center gap-3">
                <button
                  disabled={!canSave || revising}
                  onClick={handleRevise}
                  className="px-5 py-2 rounded-lg text-sm font-medium text-white transition-colors flex items-center gap-2
                    disabled:bg-blue-300 disabled:cursor-not-allowed
                    enabled:bg-blue-600 enabled:hover:bg-blue-700 enabled:cursor-pointer"
                >
                  {revising
                    ? <><Loader2 className="h-4 w-4 animate-spin" />Updating…</>
                    : 'Update on eBay'}
                </button>
                {!canSave && <span className="text-xs text-gray-400">Title required</span>}
              </div>
            </>
          )}

          {/* DRAFT listing — save / publish */}
          {listingStatus === 'DRAFT' && (
            <>
              {canSave && form.price.trim() && !form.category_id && !publishedListing && (
                <div className="flex items-start gap-2 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2.5">
                  <AlertCircle className="h-4 w-4 flex-shrink-0 mt-0.5 text-amber-500" />
                  <span>
                    <strong>Category missing</strong> — listing cannot be published without a category.
                    {' '}Click the 🔍 icon next to the Category field to search for the right eBay category.
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
                          ? <><Loader2 className="h-3 w-3 animate-spin" /> Solving…</>
                          : <><Sparkles className="h-3 w-3" /> Fix with AI</>}
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
                    <CheckCircle className="h-4 w-4" /> Listing published successfully on eBay!
                  </p>
                  <div className="flex items-center gap-3 flex-wrap">
                    {publishedListing.listing_url && (
                      <a href={publishedListing.listing_url} target="_blank" rel="noopener noreferrer"
                        className="inline-flex items-center gap-1.5 text-sm text-blue-600 hover:underline"
                      >
                        <ExternalLink className="h-3.5 w-3.5" /> View on eBay
                      </a>
                    )}
                    <Link href="/listings/new"
                      className="inline-flex items-center gap-1.5 text-sm font-medium bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg transition-colors"
                    >
                      + Create new listing
                    </Link>
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
                  {saving ? <><Loader2 className="h-4 w-4 animate-spin" />Saving…</> : 'Save Draft'}
                </button>
                <button
                  disabled={!canPublish || publishing || !!publishedListing}
                  onClick={handlePublish}
                  className="px-5 py-2 rounded-lg text-sm font-medium text-white transition-colors flex items-center gap-2
                    disabled:bg-blue-300 disabled:cursor-not-allowed
                    enabled:bg-blue-600 enabled:hover:bg-blue-700 enabled:cursor-pointer"
                >
                  {publishing ? <><Loader2 className="h-4 w-4 animate-spin" />Publishing…</> : 'Publish to eBay'}
                </button>
                {!canPublish && !publishedListing && (
                  <span className="text-xs text-gray-400">
                    {!canSave ? 'Title required' : !form.price.trim() ? 'Price required' : ''}
                  </span>
                )}
              </div>
            </>
          )}

        </div>

      </div>
    </div>
  );
}
