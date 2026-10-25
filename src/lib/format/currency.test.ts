import { describe, expect, it } from "vitest";
import { formatRubFromMinor, parseRubToMinor } from "./currency";

describe("currency format", () => {
  it("formats kopecks as rubles", () => {
    expect(formatRubFromMinor(150050)).toBe("1\u00a0500,50\u00a0₽");
    expect(formatRubFromMinor(0)).toBe("0,00\u00a0₽");
  });

  it("parses ruble input to kopecks", () => {
    expect(parseRubToMinor("1500,50")).toBe(150050);
    expect(parseRubToMinor("10.5")).toBe(1050);
    expect(parseRubToMinor("abc")).toBeNull();
  });
});
