import Link from "next/link";
import type { ReactNode } from "react";

/**
 * Renders the assistant's answer, which Gemini writes in Markdown: **bold**,
 * *italic*, `code`, "* " / "- " / "1. " lists (nested by indentation), "#"
 * headings and paragraphs. Citations like [1] link to the matching source.
 *
 * Built from React elements only (never innerHTML), so text from the model
 * can't inject markup.
 */
interface Source { id: string; title: string }

type Block =
  | { kind: "p"; text: string }
  | { kind: "h"; text: string }
  | { kind: "list"; ordered: boolean; items: { text: string; children: Block[] }[] };

const BULLET = /^(\s*)([*\-•]|\d+[.)])\s+(.*)$/;

function parse(lines: string[]): Block[] {
  const blocks: Block[] = [];
  let i = 0;

  while (i < lines.length) {
    const line = lines[i];

    if (!line.trim()) { i++; continue; }

    const heading = line.match(/^\s*#{1,6}\s+(.*)$/);
    if (heading) { blocks.push({ kind: "h", text: heading[1] }); i++; continue; }

    const bullet = line.match(BULLET);
    if (bullet) {
      const indent = bullet[1].length;
      const ordered = /\d/.test(bullet[2]);
      const items: { text: string; children: Block[] }[] = [];

      while (i < lines.length) {
        const m = lines[i].match(BULLET);
        if (!m || m[1].length < indent) break;

        if (m[1].length > indent) {
          // A deeper list belongs to the previous item.
          const nested: string[] = [];
          while (i < lines.length) {
            const n = lines[i].match(BULLET);
            if (!n || n[1].length <= indent) break;
            nested.push(lines[i]);
            i++;
          }
          if (items.length) items[items.length - 1].children.push(...parse(nested));
          else items.push({ text: "", children: parse(nested) });
          continue;
        }

        items.push({ text: m[3], children: [] });
        i++;
      }

      blocks.push({ kind: "list", ordered, items });
      continue;
    }

    // A paragraph runs until a blank line, heading or list.
    const para: string[] = [];
    while (i < lines.length && lines[i].trim() && !BULLET.test(lines[i]) && !/^\s*#{1,6}\s/.test(lines[i])) {
      para.push(lines[i].trim());
      i++;
    }
    blocks.push({ kind: "p", text: para.join(" ") });
  }

  return blocks;
}

function inline(text: string, sources: Source[], key = ""): ReactNode[] {
  const out: ReactNode[] = [];
  const pattern = /(\*\*(.+?)\*\*|`([^`]+)`|\[(\d+)\]|(?<![\w*])\*(?!\s)([^*]+?)\*(?![\w*]))/g;
  let last = 0;
  let n = 0;

  for (const m of text.matchAll(pattern)) {
    const at = m.index ?? 0;
    if (at > last) out.push(text.slice(last, at));
    const k = `${key}-${n++}`;

    if (m[2] !== undefined) out.push(<strong key={k}>{inline(m[2], sources, k)}</strong>);
    else if (m[3] !== undefined) out.push(<code key={k}>{m[3]}</code>);
    else if (m[4] !== undefined) {
      const source = sources[Number(m[4]) - 1];
      out.push(source
        ? <Link key={k} className="chat-cite" href={`/documents/${source.id}`} title={source.title}>[{m[4]}]</Link>
        : m[0]);
    } else if (m[5] !== undefined) out.push(<em key={k}>{inline(m[5], sources, k)}</em>);

    last = at + m[0].length;
  }

  if (last < text.length) out.push(text.slice(last));
  return out;
}

function render(blocks: Block[], sources: Source[], key = "b"): ReactNode[] {
  return blocks.map((block, i) => {
    const k = `${key}${i}`;
    if (block.kind === "h") return <p key={k} className="chat-md-heading">{inline(block.text, sources, k)}</p>;
    if (block.kind === "p") return <p key={k}>{inline(block.text, sources, k)}</p>;
    const items = block.items.map((item, j) => (
      <li key={`${k}-${j}`}>{inline(item.text, sources, `${k}-${j}`)}{item.children.length > 0 && render(item.children, sources, `${k}-${j}-`)}</li>
    ));
    return block.ordered ? <ol key={k}>{items}</ol> : <ul key={k}>{items}</ul>;
  });
}

export default function ChatMarkdown({ text, sources = [] }: { text: string; sources?: Source[] }) {
  return <div className="chat-markdown">{render(parse(text.replace(/\r\n?/g, "\n").split("\n")), sources)}</div>;
}
