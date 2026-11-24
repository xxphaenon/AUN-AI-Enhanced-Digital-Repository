import Link from "next/link";
import Icon from "./Icon";

export interface DocumentItem {
  artifactId: string;
  title: string;
  snippet?: string;
  author?: string;
  department?: string;
  courseCode?: string;
  year?: string | number;
  documentType?: string;
  date?: string;
  relevanceScore?: number;
  /** Calibrated 0-100 figure from the backend; preferred over relevanceScore. */
  matchPercent?: number;
}

export default function DocumentRow({ document }: { document: DocumentItem }) {
  return (
    <article className="document-row">
      <div className="document-icon"><Icon name="file" size={18} /></div>
      <div className="document-main">
        <Link className="document-title" href={`/documents/${document.artifactId}`}>
          {document.title}
        </Link>
        {document.snippet && <p className="document-snippet">{document.snippet.length > 320 ? `${document.snippet.slice(0, 320)}…` : document.snippet}</p>}
        {(document.documentType || document.author || document.courseCode || document.department || document.year || document.date) && (
          <div className="document-meta">
            {document.documentType && <span className="document-tag">{document.documentType}</span>}
            {document.author && <span>Author: {document.author}</span>}
            {document.courseCode && <span>Course: {document.courseCode}</span>}
            {document.department && <span>Department: {document.department}</span>}
            {document.year && <span>Year: {document.year}</span>}
            {document.date && <span>{document.date}</span>}
          </div>
        )}
      </div>
      <div className="document-trailing">
        {typeof document.matchPercent === "number" ? (
          <span className="relevance">{document.matchPercent}% match</span>
        ) : typeof document.relevanceScore === "number" && (
          <span className="relevance">{Math.round(document.relevanceScore * 100)}% match</span>
        )}
        <Link
          aria-label={`View ${document.title}`}
          className="document-open-link"
          href={`/documents/${document.artifactId}`}
        >
          <span>View</span>
          <Icon name="arrow" size={16} />
        </Link>
      </div>
    </article>
  );
}