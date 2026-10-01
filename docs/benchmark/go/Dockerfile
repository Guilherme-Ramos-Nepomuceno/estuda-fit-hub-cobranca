FROM golang:1.26-alpine AS build
WORKDIR /src
COPY go.mod ./
COPY . .
RUN go mod tidy && CGO_ENABLED=0 go build -ldflags="-s -w" -o /out/app . && go version > /out/go-version.txt

FROM scratch
COPY --from=build /out/ /
ENTRYPOINT ["/app"]
