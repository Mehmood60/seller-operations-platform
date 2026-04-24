'use client';

import { useState, useEffect } from 'react';
import { ai as aiApi } from '@/lib/api';
import {
  Sparkles, Loader2, Copy, Check, Trash2, MessageSquareReply,
  AlertCircle, ChevronDown, ChevronUp, Clock,
} from 'lucide-react';
import type { FeedbackType, FeedbackTone, FeedbackHistoryItem } from '@/types';

// ─── Options ─────────────────────────────────────────────────────────────────

const TYPE_OPTIONS: { value: FeedbackType; label: string; desc: string }[] = [
  { value: 'negative_feedback', label: '⭐ Negative Feedback',    desc: 'Buyer left a negative eBay rating' },
  { value: 'buyer_message',     label: '💬 Buyer Message',        desc: 'Buyer sent a question or complaint' },
  { value: 'return_request',    label: '📦 Return Request',       desc: 'Buyer wants to return the item' },
  { value: 'not_received',      label: '🚚 Item Not Received',    desc: 'Buyer claims the package never arrived' },
];

const TONE_OPTIONS: { value: FeedbackTone; label: string; color: string }[] = [
  { value: 'apologetic',   label: '🙏 Apologetic',           color: 'bg-purple-100 text-purple-700 border-purple-200' },
  { value: 'refund',       label: '💶 Offer Refund',         color: 'bg-green-100 text-green-700 border-green-200' },
  { value: 'replacement',  label: '🔄 Offer Replacement',   color: 'bg-blue-100 text-blue-700 border-blue-200' },
  { value: 'explanation',  label: '📋 Explanation (not our fault)', color: 'bg-gray-100 text-gray-700 border-gray-200' },
  { value: 'firm',         label: '✋ Firm but Polite',      color: 'bg-orange-100 text-orange-700 border-orange-200' },
];

const HISTORY_KEY = 'feedback_history';
const MAX_HISTORY = 20;

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function FeedbackPage() {
  const [feedbackText, setFeedbackText] = useState('');
  const [type, setType]                 = useState<FeedbackType>('buyer_message');
  const [tone, setTone]                 = useState<FeedbackTone>('apologetic');
  const [loading, setLoading]           = useState(false);
  const [error, setError]               = useState<string | null>(null);
  const [response, setResponse]         = useState('');
  const [copied, setCopied]             = useState(false);
  const [history, setHistory]           = useState<FeedbackHistoryItem[]>([]);
  const [expandedHistoryId, setExpandedHistoryId] = useState<string | null>(null);

  useEffect(() => {
    try {
      const saved = localStorage.getItem(HISTORY_KEY);
      if (saved) setHistory(JSON.parse(saved));
    } catch { /* ignore */ }
  }, []);

  const saveHistory = (items: FeedbackHistoryItem[]) => {
    setHistory(items);
    try { localStorage.setItem(HISTORY_KEY, JSON.stringify(items)); } catch { /* ignore */ }
  };

  const handleGenerate = async () => {
    if (!feedbackText.trim() || loading) return;
    setLoading(true);
    setError(null);
    setResponse('');
    setCopied(false);
    try {
      const res = await aiApi.feedbackResponse(feedbackText.trim(), type, tone);
      const text = res.data.response;
      setResponse(text);
      const item: FeedbackHistoryItem = {
        id:           Math.random().toString(36).slice(2),
        feedbackText: feedbackText.trim(),
        type,
        tone,
        response:     text,
        createdAt:    new Date().toISOString(),
      };
      saveHistory([item, ...history].slice(0, MAX_HISTORY));
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Antwort konnte nicht generiert werden.');
    } finally {
      setLoading(false);
    }
  };

  const handleCopy = async (text: string) => {
    await navigator.clipboard.writeText(text).catch(() => {});
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const clearHistory = () => {
    if (!confirm('Delete all history?')) return;
    saveHistory([]);
  };

  const charCount   = feedbackText.length;
  const overLimit   = charCount > 2000;
  const selectedType = TYPE_OPTIONS.find(o => o.value === type);

  return (
    <div className="space-y-6 max-w-3xl">

      {/* Header */}
      <div>
        <div className="flex items-center gap-2 mb-1">
          <MessageSquareReply className="h-6 w-6 text-purple-600" />
          <h1 className="text-2xl font-bold text-gray-900">AI Feedback Replies</h1>
        </div>
        <p className="text-sm text-gray-500">
          Paste a buyer message or negative feedback — AI drafts a professional reply in German, ready to copy and send.
        </p>
      </div>

      {/* Input card */}
      <div className="bg-white border border-gray-200 rounded-xl p-5 space-y-4 shadow-sm">

        {/* Feedback type selector */}
        <div>
          <p className="text-sm font-medium text-gray-700 mb-2">Message Type</p>
          <div className="grid grid-cols-2 gap-2">
            {TYPE_OPTIONS.map(o => (
              <button
                key={o.value}
                onClick={() => setType(o.value)}
                className={`text-left px-3 py-2.5 rounded-lg border text-sm transition-all ${
                  type === o.value
                    ? 'border-purple-400 bg-purple-50 text-purple-800'
                    : 'border-gray-200 text-gray-600 hover:border-purple-200 hover:bg-purple-50/40'
                }`}
              >
                <div className="font-medium">{o.label}</div>
                <div className="text-xs text-gray-400 mt-0.5">{o.desc}</div>
              </button>
            ))}
          </div>
        </div>

        {/* Tone selector */}
        <div>
          <p className="text-sm font-medium text-gray-700 mb-2">Reply Tone</p>
          <div className="flex flex-wrap gap-2">
            {TONE_OPTIONS.map(o => (
              <button
                key={o.value}
                onClick={() => setTone(o.value)}
                className={`px-3 py-1.5 rounded-full border text-xs font-medium transition-all ${
                  tone === o.value
                    ? o.color + ' ring-2 ring-offset-1 ring-purple-300'
                    : 'border-gray-200 text-gray-600 hover:border-gray-300 bg-gray-50'
                }`}
              >
                {o.label}
              </button>
            ))}
          </div>
        </div>

        {/* Feedback text input */}
        <div>
          <div className="flex items-center justify-between mb-1.5">
            <p className="text-sm font-medium text-gray-700">
              {selectedType?.label ?? 'Originalnachricht'} einfügen
            </p>
            <span className={`text-xs tabular-nums ${overLimit ? 'text-red-500 font-semibold' : 'text-gray-400'}`}>
              {charCount} / 2000
            </span>
          </div>
          <textarea
            value={feedbackText}
            onChange={e => setFeedbackText(e.target.value)}
            placeholder="Paste the buyer's message or feedback here…"
            rows={5}
            maxLength={2100}
            className="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-200 resize-none placeholder:text-gray-400"
          />
          {overLimit && (
            <p className="text-xs text-red-500 mt-1 flex items-center gap-1">
              <AlertCircle className="h-3 w-3" /> Maximum 2000 characters allowed.
            </p>
          )}
        </div>

        {/* Generate button */}
        <button
          onClick={handleGenerate}
          disabled={!feedbackText.trim() || overLimit || loading}
          className="w-full flex items-center justify-center gap-2 py-2.5 bg-purple-600 hover:bg-purple-700 disabled:bg-purple-300 disabled:cursor-not-allowed text-white rounded-lg text-sm font-medium transition-colors"
        >
          {loading
            ? <><Loader2 className="h-4 w-4 animate-spin" />Generating reply…</>
            : <><Sparkles className="h-4 w-4" />Generate Reply</>
          }
        </button>

        {error && (
          <div className="flex items-start gap-2 text-red-600 text-sm bg-red-50 border border-red-200 rounded-lg px-3 py-2.5">
            <AlertCircle className="h-4 w-4 mt-0.5 flex-shrink-0" />{error}
          </div>
        )}
      </div>

      {/* Generated response */}
      {response && (
        <div className="bg-gradient-to-br from-purple-50 to-indigo-50 border border-purple-200 rounded-xl p-5 space-y-3">
          <div className="flex items-center justify-between">
            <p className="text-sm font-semibold text-purple-800 flex items-center gap-1.5">
              <Sparkles className="h-4 w-4" />
              AI-Generated Reply
            </p>
            <button
              onClick={() => handleCopy(response)}
              className="flex items-center gap-1.5 px-3 py-1.5 bg-white border border-purple-200 hover:bg-purple-50 text-purple-700 rounded-lg text-xs font-medium transition-colors"
            >
              {copied
                ? <><Check className="h-3.5 w-3.5 text-green-600" /><span className="text-green-700">Kopiert!</span></>
                : <><Copy className="h-3.5 w-3.5" />Kopieren</>
              }
            </button>
          </div>
          <div className="bg-white border border-purple-100 rounded-lg px-4 py-3 text-sm text-gray-800 leading-relaxed whitespace-pre-wrap">
            {response}
          </div>
          <p className="text-xs text-purple-500">
            Tip: Review and adjust the reply before sending it to the buyer.
          </p>
        </div>
      )}

      {/* History */}
      {history.length > 0 && (
        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold text-gray-700 flex items-center gap-1.5">
              <Clock className="h-4 w-4 text-gray-400" />
              Recent replies ({history.length})
            </h2>
            <button
              onClick={clearHistory}
              className="flex items-center gap-1 text-xs text-red-400 hover:text-red-600 transition-colors"
            >
              <Trash2 className="h-3 w-3" /> Clear history
            </button>
          </div>

          <div className="space-y-2">
            {history.map(item => {
              const isExpanded = expandedHistoryId === item.id;
              const typeLabel  = TYPE_OPTIONS.find(o => o.value === item.type)?.label ?? item.type;
              const toneOpt    = TONE_OPTIONS.find(o => o.value === item.tone);
              const date       = new Date(item.createdAt).toLocaleString('de-DE', {
                day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
              });
              return (
                <div key={item.id} className="border border-gray-200 rounded-xl overflow-hidden bg-white">
                  <button
                    onClick={() => setExpandedHistoryId(isExpanded ? null : item.id)}
                    className="w-full flex items-center justify-between px-4 py-3 hover:bg-gray-50 transition-colors text-left"
                  >
                    <div className="flex items-center gap-2 min-w-0">
                      <span className="text-xs text-gray-400 shrink-0">{date}</span>
                      <span className="text-xs font-medium text-gray-600 shrink-0">{typeLabel}</span>
                      {toneOpt && (
                        <span className={`text-xs px-2 py-0.5 rounded-full border shrink-0 ${toneOpt.color}`}>
                          {toneOpt.label}
                        </span>
                      )}
                      <span className="text-xs text-gray-500 truncate">
                        {item.feedbackText.slice(0, 60)}{item.feedbackText.length > 60 ? '…' : ''}
                      </span>
                    </div>
                    {isExpanded
                      ? <ChevronUp className="h-3.5 w-3.5 text-gray-400 shrink-0" />
                      : <ChevronDown className="h-3.5 w-3.5 text-gray-400 shrink-0" />
                    }
                  </button>

                  {isExpanded && (
                    <div className="px-4 pb-4 space-y-3 border-t border-gray-100">
                      <div className="mt-3">
                        <p className="text-xs font-medium text-gray-500 mb-1">Original message:</p>
                        <div className="text-xs text-gray-700 bg-gray-50 border border-gray-100 rounded-lg px-3 py-2 whitespace-pre-wrap">
                          {item.feedbackText}
                        </div>
                      </div>
                      <div>
                        <div className="flex items-center justify-between mb-1">
                          <p className="text-xs font-medium text-gray-500">AI reply:</p>
                          <HistoryCopyButton text={item.response} />
                        </div>
                        <div className="text-xs text-gray-800 bg-purple-50 border border-purple-100 rounded-lg px-3 py-2 whitespace-pre-wrap leading-relaxed">
                          {item.response}
                        </div>
                      </div>
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}

function HistoryCopyButton({ text }: { text: string }) {
  const [copied, setCopied] = useState(false);
  const handleCopy = async () => {
    await navigator.clipboard.writeText(text).catch(() => {});
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };
  return (
    <button
      onClick={handleCopy}
      className="flex items-center gap-1 text-xs text-purple-600 hover:text-purple-800 transition-colors"
    >
      {copied
        ? <><Check className="h-3 w-3 text-green-600" />Kopiert</>
        : <><Copy className="h-3 w-3" />Kopieren</>
      }
    </button>
  );
}
