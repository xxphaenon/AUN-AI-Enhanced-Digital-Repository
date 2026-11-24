import HomeSearch from "./components/HomeSearch";

export default async function HomePage({
  searchParams,
}: {
  searchParams: Promise<{ q?: string; type?: string }>;
}) {
  const { q = "", type = "all" } = await searchParams;

  return (
    <HomeSearch
      key={`${q}:${type}`}
      initialQuery={q}
      initialType={type}
    />
  );
}