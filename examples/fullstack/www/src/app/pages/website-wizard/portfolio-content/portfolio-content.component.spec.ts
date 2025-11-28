import { ComponentFixture, TestBed } from '@angular/core/testing';

import { PortfolioContentComponent } from './portfolio-content.component';

describe('PortfolioContentComponent', () => {
  let component: PortfolioContentComponent;
  let fixture: ComponentFixture<PortfolioContentComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
    imports: [PortfolioContentComponent]
})
    .compileComponents();

    fixture = TestBed.createComponent(PortfolioContentComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
