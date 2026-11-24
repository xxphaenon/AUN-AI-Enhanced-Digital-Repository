"use client";

import { FormEvent, useCallback, useEffect, useState } from "react";
import DocumentRow from "./DocumentRow";
import Icon from "./Icon";
import { DOCUMENT_TYPES, search, type SearchResult } from "../lib/api";

const documentTypes = [{ value: "all", label: "All documents" }, ...DOCUMENT_TYPES];

export default function HomeSearch({
  initialQuery,
  initialType,
}: {
  initialQuery: string;
  initialType: string;
}) {
  const [query, setQuery] = useState(() => initialQuery);
  const [documentType, setDocumentType] = useState(() => initialType);
  const [results, setResults] = useState<SearchResult[]>([]);
  const [hasSearched, setHasSearched] = useState(() => Boolean(initialQuery.trim()));
  const [loading, setLoading] = useState(() => Boolean(initialQuery.trim()));
  const [error, setError] = useState("");

  // Semantic (AI) search over approved documents.
  const requestSearch = useCallback((term: string, type: string) => search(term, type, 10), []);

  useEffect(() => {
    if (!initialQuery.trim()) {
      return;
    }

    let active = true;
    requestSearch(initialQuery, initialType)
      .then((searchResults) => {
        if (active) setResults(searchResults);
      })
      .catch((searchError: unknown) => {
        if (!active) return;
        console.error("Search failed:", searchError);
        setResults([]);
        setError(searchError instanceof Error ? searchError.message : "Search is unavailable right now. Please try again.");
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [initialQuery, initialType, requestSearch]);

  const runSearch = async (term: string, type: string) => {
    if (!term.trim()) {
      setHasSearched(false);
      setResults([]);
      setError("");
      return;
    }
    if (term.trim().length < 2) {
      setError("Type at least 2 characters to search.");
      return;
    }

    setLoading(true);
    setHasSearched(true);
    setError("");

    try {
      setResults(await requestSearch(term, type));
    } catch (searchError) {
      console.error("Search failed:", searchError);
      setResults([]);
      setError(searchError instanceof Error ? searchError.message : "Search is unavailable right now. Please try again.");
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    void runSearch(query, documentType);
  };

  return (
    <div className={`home-dashboard${hasSearched ? " has-results" : ""}`}>
      <header className="page-heading home-hero">
        <div className="university-context home-university-context">
          <span className="home-brand-pill">AUN Digital Repository</span>
        </div>
        <div className="home-hero-copy">
          <h1>Discover University<br />Knowledge</h1>
          <p>Explore senior projects, research papers, course materials and more from across the university.</p>
        </div>
        <section aria-label="Repository search" className="repository-search" id="repository-search">
          <form className="search-form" onSubmit={handleSubmit} role="search">
            <Icon name="search" size={20} />
            <input
              aria-label="Search university projects and resources"
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Search by topic, title, author or keyword..."
              value={query}
            />
            <select
              aria-label="Filter by document type"
              className="field-select search-filter"
              onChange={(event) => setDocumentType(event.target.value)}
              value={documentType}
            >
              {documentTypes.map((type) => (
                <option key={type.value} value={type.value}>{type.label}</option>
              ))}
            </select>
            <button className="button-primary" disabled={loading} type="submit">
              {loading ? "Searching..." : "Search"}
            </button>
          </form>
        </section>
      </header>

      {error && <p className="alert-error" role="alert">{error}</p>}

      {hasSearched ? (
        <section aria-live="polite">
          <div className="results-summary">
            <span>{loading ? "Searching the repository..." : `${results.length} ${results.length === 1 ? "result" : "results"}`}</span>
            {query.trim() && <span>For “{query.trim()}”</span>}
          </div>
          <div className="content-section">
            {loading ? (
              <div className="empty-state">Searching university resources...</div>
            ) : results.length > 0 ? (
              <div className="document-list">
                {results.map((result) => (
                  <DocumentRow
                    document={{
                      artifactId: result.artifactId,
                      title: result.title,
                      snippet: result.relevantSnippets[0],
                      author: result.author ?? undefined,
                      courseCode: result.courseCode ?? undefined,
                      year: result.academicYear ?? undefined,
                      documentType: result.documentTypeLabel ?? undefined,
                      relevanceScore: result.relevanceScore,
                      matchPercent: result.matchPercent,
                    }}
                    key={result.artifactId}
                  />
                ))}
              </div>
            ) : (
              <div className="empty-state">
                <strong>No projects found</strong>
                Try a different search term or document type.
              </div>
            )}
          </div>
        </section>
      ) : null}
    </div>
  );
}