'use client';

import { useRef, useState, forwardRef, useImperativeHandle, memo } from 'react';

// ─── Toolbar helpers ──────────────────────────────────────────────────────────

function Btn({
  onMouseDown,
  title,
  children,
}: {
  onMouseDown: (e: React.MouseEvent) => void;
  title: string;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onMouseDown={onMouseDown}
      title={title}
      className="px-2 py-1 rounded text-xs font-medium text-gray-600 hover:bg-gray-200 hover:text-gray-900 transition-colors select-none"
    >
      {children}
    </button>
  );
}

const Sep = () => (
  <div className="w-px h-4 bg-gray-300 mx-0.5 self-center shrink-0" />
);

// ─── Static inner editor ──────────────────────────────────────────────────────
//
// memo(() => true) means this component NEVER re-renders after mount.
// React therefore never tries to reconcile its children, which eliminates the
// "removeChild: The node is not a child" crash that occurs when browser
// spell-check / Grammarly inject <span> nodes into the contentEditable div
// and React subsequently tries to reconcile them during any parent state change.

const StaticEditor = memo(
  function StaticEditor({
    defaultValue,
    editorRef,
    onChangeFn,
  }: {
    defaultValue: string;
    editorRef: React.MutableRefObject<HTMLDivElement | null>;
    onChangeFn: React.MutableRefObject<(html: string) => void>;
  }) {
    return (
      <div
        ref={editorRef}
        contentEditable
        suppressContentEditableWarning
        // eslint-disable-next-line react/no-danger
        dangerouslySetInnerHTML={{ __html: defaultValue }}
        spellCheck={false}
        autoCorrect="off"
        autoCapitalize="off"
        data-gramm="false"
        data-gramm_editor="false"
        data-enable-grammarly="false"
        onInput={() => onChangeFn.current(editorRef.current?.innerHTML ?? '')}
        onBlur={() => onChangeFn.current(editorRef.current?.innerHTML ?? '')}
        style={{
          minHeight: '200px',
          padding: '10px 12px',
          outline: 'none',
          wordBreak: 'break-word',
          fontSize: '14px',
          lineHeight: '1.6',
        }}
      />
    );
  },
  () => true,  // never re-render — content is managed imperatively via editorRef
);

// ─── Public types ─────────────────────────────────────────────────────────────

interface Props {
  defaultValue: string;
  onChange: (html: string) => void;
}

export interface RichTextEditorHandle {
  clear: () => void;
  setValue: (html: string) => void;
}

// ─── Main component ───────────────────────────────────────────────────────────

export const RichTextEditor = forwardRef<RichTextEditorHandle, Props>(
  function RichTextEditor({ defaultValue, onChange }, forwardedRef) {
    const editorRef  = useRef<HTMLDivElement | null>(null);
    const onChangeFn = useRef(onChange);
    onChangeFn.current = onChange;

    const [showSource, setShowSource] = useState(false);
    const [sourceHtml, setSourceHtml] = useState(defaultValue ?? '');

    useImperativeHandle(forwardedRef, () => ({
      clear: () => {
        if (editorRef.current) {
          editorRef.current.innerHTML = '';
          editorRef.current.style.display = '';
        }
        setShowSource(false);
        setSourceHtml('');
        onChangeFn.current('');
      },
      setValue: (html: string) => {
        if (editorRef.current) {
          editorRef.current.innerHTML = html;
          editorRef.current.style.display = '';
        }
        setShowSource(false);
        setSourceHtml(html);
        onChangeFn.current(html);
      },
    }));

    const exec = (e: React.MouseEvent, cmd: string, val?: string) => {
      e.preventDefault();
      editorRef.current?.focus();
      document.execCommand(cmd, false, val);
      onChangeFn.current(editorRef.current?.innerHTML ?? '');
    };

    const toggleSource = () => {
      if (!showSource) {
        const html = editorRef.current?.innerHTML ?? '';
        setSourceHtml(html);
        if (editorRef.current) editorRef.current.style.display = 'none';
      } else {
        if (editorRef.current) {
          editorRef.current.innerHTML = sourceHtml;
          editorRef.current.style.display = '';
        }
        onChangeFn.current(sourceHtml);
      }
      setShowSource(v => !v);
    };

    const b = (cmd: string, val?: string) => (ev: React.MouseEvent) =>
      exec(ev, cmd, val);

    return (
      <div className="border border-gray-200 rounded-lg overflow-hidden focus-within:ring-2 focus-within:ring-blue-200 focus-within:border-blue-300 transition-shadow">

        {/* ── Toolbar ──────────────────────────────────────────────────── */}
        <div className="flex items-center gap-0.5 px-2 py-1.5 bg-gray-50 border-b border-gray-200 flex-wrap">
          <Btn onMouseDown={b('bold')}      title="Fett (Strg+B)">      <strong>B</strong>                        </Btn>
          <Btn onMouseDown={b('italic')}    title="Kursiv (Strg+I)">    <em className="italic font-medium">I</em> </Btn>
          <Btn onMouseDown={b('underline')} title="Unterstrichen">      <span className="underline">U</span>      </Btn>
          <Sep />
          <Btn onMouseDown={b('formatBlock', 'h2')} title="Überschrift">    <span className="font-bold">H2</span></Btn>
          <Btn onMouseDown={b('formatBlock', 'h3')} title="Unterüberschrift"><span className="font-bold">H3</span></Btn>
          <Btn onMouseDown={b('formatBlock', 'p')}  title="Absatz">         <span>¶</span>                       </Btn>
          <Sep />
          <Btn onMouseDown={b('insertUnorderedList')} title="Aufzählungsliste">• Liste</Btn>
          <Btn onMouseDown={b('insertOrderedList')}   title="Nummerierte Liste">1. Liste</Btn>
          <Sep />
          <Btn onMouseDown={b('undo')} title="Rückgängig (Strg+Z)">↩</Btn>
          <Btn onMouseDown={b('redo')} title="Wiederholen (Strg+Y)">↪</Btn>
          <Sep />
          <Btn onMouseDown={b('removeFormat')} title="Formatierung entfernen">
            <span className="line-through text-gray-400">A</span>
          </Btn>

          <div className="ml-auto">
            <button
              type="button"
              onClick={toggleSource}
              title={showSource ? 'Visuell bearbeiten' : 'HTML-Quelltext anzeigen'}
              className={`px-2 py-1 rounded text-xs font-mono transition-colors ${
                showSource
                  ? 'bg-blue-100 text-blue-700'
                  : 'text-gray-400 hover:bg-gray-200 hover:text-gray-600'
              }`}
            >
              &lt;/&gt;
            </button>
          </div>
        </div>

        {/* ── Editor ───────────────────────────────────────────────────── */}
        <div className="prose prose-sm max-w-none">
          <StaticEditor
            defaultValue={defaultValue ?? ''}
            editorRef={editorRef}
            onChangeFn={onChangeFn}
          />
        </div>

        {/* ── HTML source textarea ─────────────────────────────────────── */}
        {showSource && (
          <textarea
            value={sourceHtml}
            onChange={e => {
              setSourceHtml(e.target.value);
              onChangeFn.current(e.target.value);
            }}
            rows={10}
            spellCheck={false}
            className="w-full px-3 py-2.5 text-xs font-mono text-gray-700 focus:outline-none resize-y bg-white border-t border-gray-100"
            placeholder="HTML-Code hier eingeben…"
          />
        )}
      </div>
    );
  },
);
