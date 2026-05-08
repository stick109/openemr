# syntax=docker/dockerfile:1.7

FROM mcr.microsoft.com/dotnet/sdk:9.0 AS build
WORKDIR /src
COPY OpenEmr.Dashboard.sln ./
COPY src/OpenEmr.Dashboard/OpenEmr.Dashboard.csproj src/OpenEmr.Dashboard/
RUN dotnet restore src/OpenEmr.Dashboard/OpenEmr.Dashboard.csproj
COPY src/OpenEmr.Dashboard/ src/OpenEmr.Dashboard/
RUN dotnet publish src/OpenEmr.Dashboard/OpenEmr.Dashboard.csproj \
    --configuration Release --no-restore -o /app/publish

FROM mcr.microsoft.com/dotnet/aspnet:9.0 AS runtime
ENV ASPNETCORE_URLS=http://+:8080 \
    ASPNETCORE_ENVIRONMENT=Production \
    DOTNET_RUNNING_IN_CONTAINER=true \
    DOTNET_NOLOGO=1

RUN apt-get update \
    && apt-get install --no-install-recommends --yes curl \
    && rm -rf /var/lib/apt/lists/*

# Unprivileged user — uid/gid 10002 (10001 is taken by agent-service).
RUN groupadd --system --gid 10002 dashboard \
    && useradd --system --uid 10002 --gid dashboard --home /app --shell /usr/sbin/nologin dashboard

WORKDIR /app
COPY --from=build /app/publish ./
RUN chown -R dashboard:dashboard /app
USER dashboard

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl --fail --silent --show-error http://127.0.0.1:8080/healthz || exit 1

ENTRYPOINT ["dotnet", "OpenEmr.Dashboard.dll"]
