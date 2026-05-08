namespace OpenEmr.Dashboard.Fhir.Records;

/// <summary>
/// Result wrapper for a single dashboard card's data fetch. The
/// <see cref="Index"/> page model fans out one fetch per card via
/// <see cref="System.Threading.Tasks.Task.WhenAll(System.Threading.Tasks.Task[])"/>;
/// each fetch returns a <see cref="CardResult{T}"/> so a single failed call
/// surfaces an error inside that card without breaking the whole page.
///
/// Contract: <see cref="Error"/> is non-null on failure (HTTP error text, or
/// the diagnostics text from a FHIR <c>OperationOutcome</c>) and
/// <see cref="Data"/> is an empty list. On success <see cref="Error"/> is
/// <c>null</c> and <see cref="Data"/> contains the fetched resources.
/// </summary>
public sealed record CardResult<T>(IReadOnlyList<T> Data, string? Error)
{
    public static CardResult<T> Empty { get; } =
        new(Array.Empty<T>(), null);

    public static CardResult<T> Failure(string error) =>
        new(Array.Empty<T>(), error);

    public bool HasError => this.Error is not null;
}
